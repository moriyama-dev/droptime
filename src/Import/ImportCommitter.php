<?php

declare(strict_types=1);

namespace DropTime\Import;

use DropTime\Db\Database;
use DropTime\Matching\SwimmerMatcher;
use DropTime\Matching\TeamResolver;
use DropTime\Parser\ParsedEntry;
use DropTime\Parser\ParsedEvent;
use DropTime\Parser\ParsedResultRow;
use DropTime\Parser\ParsedSwimmer;
use DropTime\Parser\ParseResult;
use DropTime\Support\Clock;

/**
 * 承認されたパース結果を DB に書き込む。
 *
 * 全体を 1 トランザクションで行い、途中で失敗したら何も残さない。
 * 同じ大会（名前と開始日が一致）への再投入は「種目単位の置き換え」として
 * 扱う。パーサーを直して再処理したとき、二重登録にならないようにするため。
 */
final class ImportCommitter
{
    public function __construct(
        private readonly Database $db,
        private readonly TeamResolver $teams,
        private readonly SwimmerMatcher $swimmers,
    ) {
    }

    public static function create(Database $db): self
    {
        return new self($db, new TeamResolver($db), new SwimmerMatcher($db));
    }

    /**
     * @param array{name: string, start_date: string, course: string,
     *               end_date?: ?string, host?: ?string, venue?: ?string} $meet
     * @return array{meet_id: int, events: int, entries: int, results: int}
     */
    public function commit(ParseResult $result, array $meet, string $sourceFile): array
    {
        /** @var array{meet_id: int, events: int, entries: int, results: int} $stats */
        $stats = $this->db->transaction(function (Database $db) use ($result, $meet, $sourceFile): array {
            $meetId = $this->upsertMeet($meet, $sourceFile);
            // 投入し直しなら、前回この大会で決まった選手をそのまま使う。
            $this->swimmers->rememberMeet($meetId);

            $counts = ['events' => 0, 'entries' => 0, 'results' => 0];

            foreach ($result->events() as $index => $event) {
                $eventId = $this->upsertEvent($meetId, $event, $index);
                $counts['events']++;

                foreach ($event->entries() as $entry) {
                    $this->insertEntry($eventId, $entry);
                    $counts['entries']++;
                }

                foreach ($event->results() as $row) {
                    $this->insertResult($eventId, $row);
                    $counts['results']++;
                }
            }

            return ['meet_id' => $meetId] + $counts;
        });

        return $stats;
    }

    /** @param array<string, mixed> $meet */
    private function upsertMeet(array $meet, string $sourceFile): int
    {
        $name = trim((string) ($meet['name'] ?? ''));
        $startDate = trim((string) ($meet['start_date'] ?? ''));
        $course = (string) ($meet['course'] ?? '');

        if ($name === '' || $startDate === '' || $course === '') {
            throw new ImportException('大会名・開始日・コースが揃っていないため投入できません。');
        }

        $values = [
            'name' => $name,
            'host' => self::nullable($meet['host'] ?? null),
            'start_date' => $startDate,
            'end_date' => self::nullable($meet['end_date'] ?? null),
            'course' => $course,
            'venue' => self::nullable($meet['venue'] ?? null),
            'source_file' => $sourceFile,
        ];

        $existing = $this->db->selectOne(
            'SELECT id FROM meets WHERE name = :name AND start_date = :start_date',
            ['name' => $name, 'start_date' => $startDate]
        );

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE meets SET host = :host, end_date = :end_date, course = :course,'
                . ' venue = :venue, source_file = :source_file, updated_at = :updated_at'
                . ' WHERE id = :id',
                [
                    'host' => $values['host'],
                    'end_date' => $values['end_date'],
                    'course' => $values['course'],
                    'venue' => $values['venue'],
                    'source_file' => $sourceFile,
                    'updated_at' => Clock::utcNow(),
                    'id' => (int) $existing['id'],
                ]
            );

            return (int) $existing['id'];
        }

        $now = Clock::utcNow();

        return $this->db->insert(
            'INSERT INTO meets (name, host, start_date, end_date, course, venue, source_file, created_at, updated_at)'
            . ' VALUES (:name, :host, :start_date, :end_date, :course, :venue, :source_file, :created_at, :updated_at)',
            $values + ['created_at' => $now, 'updated_at' => $now]
        );
    }

    /**
     * 種目を (meet_id, event_number, round) で引き当て、あれば中身を置き換える。
     * entries / results は毎回消して入れ直す（splits は results から CASCADE）。
     */
    private function upsertEvent(int $meetId, ParsedEvent $event, int $index): int
    {
        $sortOrder = $event->sortOrder !== 0 ? $event->sortOrder : $index + 1;

        $existing = $this->db->selectOne(
            'SELECT id FROM events WHERE meet_id = :meet_id AND event_number = :number AND round = :round',
            ['meet_id' => $meetId, 'number' => $event->number, 'round' => $event->round]
        );

        if ($existing !== null) {
            $eventId = (int) $existing['id'];

            $this->db->execute(
                'UPDATE events SET gender = :gender, age_group = :age_group, distance = :distance,'
                . ' stroke = :stroke, course = :course, is_relay = :is_relay,'
                . ' sort_order = :sort_order, scheduled_time = :scheduled_time'
                . ' WHERE id = :id',
                [
                    'gender' => $event->gender,
                    'age_group' => $event->ageGroup,
                    'distance' => $event->distance,
                    'stroke' => $event->stroke->value,
                    'course' => $event->course->value,
                    'is_relay' => $event->isRelay ? 1 : 0,
                    'sort_order' => $sortOrder,
                    'scheduled_time' => $event->scheduledTime,
                    'id' => $eventId,
                ]
            );

            $this->db->execute('DELETE FROM entries WHERE event_id = :id', ['id' => $eventId]);
            $this->db->execute('DELETE FROM results WHERE event_id = :id', ['id' => $eventId]);

            return $eventId;
        }

        return $this->db->insert(
            'INSERT INTO events (meet_id, event_number, round, gender, age_group, distance, stroke,'
            . ' course, is_relay, sort_order, scheduled_time)'
            . ' VALUES (:meet_id, :number, :round, :gender, :age_group, :distance, :stroke,'
            . ' :course, :is_relay, :sort_order, :scheduled_time)',
            [
                'meet_id' => $meetId,
                'number' => $event->number,
                'round' => $event->round,
                'gender' => $event->gender,
                'age_group' => $event->ageGroup,
                'distance' => $event->distance,
                'stroke' => $event->stroke->value,
                'course' => $event->course->value,
                'is_relay' => $event->isRelay ? 1 : 0,
                'sort_order' => $sortOrder,
                'scheduled_time' => $event->scheduledTime,
            ]
        );
    }

    private function insertEntry(int $eventId, ParsedEntry $entry): void
    {
        [$swimmerId, $teamId] = $this->resolvePeople($entry->swimmer, $entry->teamCode);

        $this->db->execute(
            'INSERT INTO entries (event_id, swimmer_id, team_id, relay_letter, heat, lane, seed_time_cs, seed_is_ntime)'
            . ' VALUES (:event_id, :swimmer_id, :team_id, :relay_letter, :heat, :lane, :seed_time_cs, :seed_is_ntime)',
            [
                'event_id' => $eventId,
                'swimmer_id' => $swimmerId,
                'team_id' => $teamId,
                'relay_letter' => $entry->relayLetter,
                'heat' => $entry->heat,
                'lane' => $entry->lane,
                'seed_time_cs' => $entry->seedTimeCs,
                'seed_is_ntime' => $entry->seedIsNTime ? 1 : 0,
            ]
        );
    }

    private function insertResult(int $eventId, ParsedResultRow $row): void
    {
        [$swimmerId, $teamId] = $this->resolvePeople($row->swimmer, $row->teamCode);

        $resultId = $this->db->insert(
            'INSERT INTO results (event_id, swimmer_id, team_id, relay_letter, heat, lane, age,'
            . ' final_time_cs, place, status, dq_reason, points)'
            . ' VALUES (:event_id, :swimmer_id, :team_id, :relay_letter, :heat, :lane, :age,'
            . ' :final_time_cs, :place, :status, :dq_reason, :points)',
            [
                'event_id' => $eventId,
                'swimmer_id' => $swimmerId,
                'team_id' => $teamId,
                'relay_letter' => $row->relayLetter,
                'heat' => $row->heat,
                'lane' => $row->lane,
                // レース時点の年齢。同年齢どうしの比較にだけ使う（003 参照）。
                'age' => $row->swimmer?->age,
                'final_time_cs' => $row->finalTimeCs,
                'place' => $row->place,
                'status' => $row->status,
                'dq_reason' => $row->dqReason,
                'points' => $row->points,
            ]
        );

        foreach ($row->splits as $split) {
            $this->db->execute(
                'INSERT INTO splits (result_id, distance, cumulative_time_cs)'
                . ' VALUES (:result_id, :distance, :cumulative_time_cs)',
                [
                    'result_id' => $resultId,
                    'distance' => $split->distance,
                    'cumulative_time_cs' => $split->cumulativeTimeCs,
                ]
            );
        }
    }

    /**
     * 行の主体（個人 or リレーチーム）を ID に解決する。
     *
     * @return array{0: ?int, 1: ?int} [swimmer_id, team_id]
     */
    private function resolvePeople(?ParsedSwimmer $swimmer, ?string $teamCode): array
    {
        if ($swimmer !== null) {
            $teamId = $this->teams->resolve($swimmer->teamCode, $swimmer->teamName);

            return [$this->swimmers->resolve($swimmer, $teamId), $teamId];
        }

        return [null, $this->teams->resolve($teamCode, null)];
    }

    private static function nullable(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
