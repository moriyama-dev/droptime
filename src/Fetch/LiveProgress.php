<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use DropTime\Db\Database;
use DropTime\Domain\LiveSchedule;
use DropTime\Import\HtmlTextExtractor;
use DropTime\Parser\HyTek\HyTekLine;
use DropTime\Parser\HyTek\HyTekResultsParser;
use DropTime\Repository\MeetRepository;
use DropTime\Support\Clock;
use DropTime\Support\Str;
use Throwable;

/**
 * 大会当日、速報サイトの「最後に終わった種目」（<日付>lastevt.htm）を読み、
 * その種目が終わった時刻を live_event_status に残す。カウントダウンはこの時刻で補正する。
 *
 * 1 大会につき通信は 2 回（目次で lastevt のページ名を確かめ、lastevt を読む）。
 * 本番サーバーの cron が数分おきに bin/live-progress.php から動かす。
 *
 * 見るのは「今日が会期中」で「誰かのお気に入りの選手が出ている」速報サイトの大会だけ。
 * 終わった時刻は、結果のページの右上の印字（"11/14/2026 - 2:08 PM" / "2026-11-01 - 12:02 PM"）。
 * 同じ種目を何度見ても、いちばん早い時刻を残す（後から再掲載されても動かさない）。
 *
 * ついでに、お気に入りの選手が泳ぎ終わった種目のページを読み、その選手の結果を live_results に置く
 *（正式な取り込み・同期を待たずにホームの「この大会の泳ぎ」に出すため）。読むのは、
 * まだ結果が無く、並びから終わったと分かる種目だけで、1 回につき最大 MAX_RESULT_PAGES ページ。
 */
final class LiveProgress
{
    /** 1 回の確認で読む結果のページの上限。 */
    private const MAX_RESULT_PAGES = 6;

    public function __construct(
        private readonly Database $db,
        private readonly PortalClient $client,
    ) {
    }

    /**
     * 今日、見張る大会。
     *
     * @return list<array{id: int, name: string, source_file: string}>
     */
    public function meetsToWatch(string $todayIso): array
    {
        $base = $this->client->baseUrl();

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'source_file' => (string) $row['source_file']],
            $this->db->select(
                'SELECT m.id, m.name, m.source_file FROM meets m'
                . ' WHERE m.start_date <= :today AND COALESCE(m.end_date, m.start_date) >= :today2'
                . ' AND m.source_file LIKE :base'
                . ' AND EXISTS (SELECT 1 FROM events e JOIN entries en ON en.event_id = e.id'
                . '   JOIN favorites f ON f.swimmer_id = en.swimmer_id WHERE e.meet_id = m.id)'
                . ' ORDER BY m.id',
                ['today' => $todayIso, 'today2' => $todayIso, 'base' => $base . '%']
            )
        );
    }

    /**
     * 1 大会ぶん確かめる。
     *
     * @param array{id: int, name: string, source_file: string} $meet
     * @return array{event: ?string, round: ?string, finished_at: ?string, note: string, results?: int}
     */
    public function check(array $meet): array
    {
        $folder = rtrim($meet['source_file'], '/') . '/';
        $index = $this->client->get($folder . 'evtindex.htm');

        if ($index['status'] !== 200) {
            return ['event' => null, 'round' => null, 'finished_at' => null, 'note' => "目次を読めません（HTTP {$index['status']}）"];
        }

        $page = PortalPages::eventIndex($index['body'])['last_event'];

        if ($page === null) {
            return ['event' => null, 'round' => null, 'finished_at' => null, 'note' => 'まだ終わった種目がありません'];
        }

        $last = $this->client->get($folder . $page);

        if ($last['status'] !== 200) {
            return ['event' => null, 'round' => null, 'finished_at' => null, 'note' => "最後に終わった種目のページを読めません（HTTP {$last['status']}）"];
        }

        $parsed = self::parseLastEvent($last['body']);

        if ($parsed === null) {
            return ['event' => null, 'round' => null, 'finished_at' => null, 'note' => '最後に終わった種目のページを読み取れません'];
        }

        $this->record($meet['id'], $parsed['event'], $parsed['round'], $parsed['finished_at']);
        $results = $this->collectResults($meet['id'], $folder, $index['body']);

        return $parsed + ['note' => '記録しました', 'results' => $results];
    }

    /**
     * お気に入りの選手が泳ぎ終わった種目の結果を読み、live_results に置く。
     *
     * @return int 置いた結果の件数
     */
    public function collectResults(int $meetId, string $folder, string $indexHtml): int
    {
        $pages = [];

        foreach (MeetProgram::parse($indexHtml) as $session) {
            foreach ($session['events'] as $event) {
                $pages[LiveSchedule::key($event['number'], $event['round'])] = $event['page'];
            }
        }

        $meets = new MeetRepository($this->db);
        $now = Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d H:i:s');
        $plan = LiveSchedule::forMeet($meets->sessionsOf($meetId), $meets->liveStatusOf($meetId), $now);

        $waiting = [];

        foreach ($this->db->select(
            'SELECT DISTINCT e.event_number, e.round, s.id AS swimmer_id, s.normalized_key, t.code AS team_code'
            . ' FROM favorites f'
            . ' JOIN entries en ON en.swimmer_id = f.swimmer_id'
            . ' JOIN events e ON e.id = en.event_id'
            . ' JOIN swimmers s ON s.id = en.swimmer_id'
            . ' LEFT JOIN teams t ON t.id = en.team_id'
            . ' WHERE e.meet_id = :meet_id'
            . ' AND NOT EXISTS (SELECT 1 FROM results r WHERE r.event_id = e.id AND r.swimmer_id = s.id)'
            . ' AND NOT EXISTS (SELECT 1 FROM live_results lr WHERE lr.meet_id = e.meet_id'
            . '   AND lr.event_number = e.event_number AND lr.round = e.round AND lr.swimmer_id = s.id)',
            ['meet_id' => $meetId]
        ) as $row) {
            $key = LiveSchedule::key((string) $row['event_number'], (string) $row['round']);

            if (($plan[$key]['status'] ?? null) !== LiveSchedule::DONE || !isset($pages[$key])) {
                continue;
            }

            $waiting[$key][] = $row;
        }

        $stored = 0;

        foreach (array_slice($waiting, 0, self::MAX_RESULT_PAGES, true) as $key => $swimmers) {
            $response = $this->client->get($folder . $pages[$key]);

            if ($response['status'] !== 200 || !PortalPages::isResultsPage($response['body'])) {
                continue;
            }

            $stored += $this->storeResults($meetId, $response['body'], $swimmers);
        }

        return $stored;
    }

    /**
     * 結果のページから、待っている選手の行を探して置く。選手は名前の正規化キーとチームで照合する。
     *
     * @param list<array<string, mixed>> $swimmers
     */
    public function storeResults(int $meetId, string $html, array $swimmers): int
    {
        try {
            $text = (new HtmlTextExtractor())->extract($html);
        } catch (Throwable) {
            return 0;
        }

        $stored = 0;

        foreach ((new HyTekResultsParser())->parse($text)->events() as $event) {
            foreach ($event->results() as $row) {
                if ($row->swimmer === null) {
                    continue;
                }

                foreach ($swimmers as $swimmer) {
                    if ((string) $swimmer['event_number'] !== $event->number || (string) $swimmer['round'] !== $event->round) {
                        continue;
                    }

                    $sameName = Str::swimmerKey($row->swimmer->lastName, $row->swimmer->firstName) === (string) $swimmer['normalized_key'];
                    $sameTeam = $swimmer['team_code'] === null || $row->teamCode === null || $row->teamCode === $swimmer['team_code'];

                    if (!$sameName || !$sameTeam) {
                        continue;
                    }

                    $stored += $this->db->execute(
                        'INSERT IGNORE INTO live_results (meet_id, event_number, round, swimmer_id, final_time_cs, place, status, age, observed_at)'
                        . ' VALUES (:meet_id, :number, :round, :swimmer_id, :time, :place, :status, :age, :observed_at)',
                        [
                            'meet_id' => $meetId,
                            'number' => $event->number,
                            'round' => $event->round,
                            'swimmer_id' => (int) $swimmer['swimmer_id'],
                            'time' => $row->finalTimeCs,
                            'place' => $row->place,
                            'status' => $row->status,
                            'age' => $row->swimmer->age,
                            'observed_at' => Clock::utcNow(),
                        ]
                    );
                }
            }
        }

        return $stored;
    }

    public function record(int $meetId, string $eventNumber, string $round, string $finishedAt): void
    {
        $this->db->execute(
            'INSERT INTO live_event_status (meet_id, event_number, round, finished_at, observed_at)'
            . ' VALUES (:meet_id, :number, :round, :finished_at, :observed_at)'
            . ' ON DUPLICATE KEY UPDATE finished_at = LEAST(finished_at, VALUES(finished_at))',
            [
                'meet_id' => $meetId,
                'number' => $eventNumber,
                'round' => $round,
                'finished_at' => $finishedAt,
                'observed_at' => Clock::utcNow(),
            ]
        );
    }

    /**
     * 「最後に終わった種目」のページから、種目番号・round・終わった時刻を読む。
     *
     * @return array{event: string, round: string, finished_at: string}|null finished_at は現地 'Y-m-d H:i:s'
     */
    public static function parseLastEvent(string $html): ?array
    {
        try {
            $text = (new HtmlTextExtractor())->extract($html);
        } catch (Throwable) {
            return null;
        }

        $finishedAt = null;
        $event = null;
        $round = 'timed';

        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            if ($finishedAt === null) {
                $finishedAt = self::stamp($line);
            }

            if ($event === null) {
                $header = HyTekLine::eventHeader($line);

                if ($header !== null) {
                    $event = $header['number'];

                    continue;
                }
            }

            if ($event !== null) {
                $columns = HyTekLine::columnHeader($line);

                if ($columns !== null) {
                    $round = match (true) {
                        $columns['has_seed'] && $columns['has_prelims'] => 'prelim',
                        $columns['has_prelims'] && $columns['has_finals'] => 'final',
                        default => 'timed',
                    };

                    break;
                }
            }
        }

        if ($finishedAt === null || $event === null) {
            return null;
        }

        return ['event' => $event, 'round' => $round, 'finished_at' => $finishedAt];
    }

    /** "… 11/14/2026 - 2:08 PM" / "… 2026-11-01 - 12:02 PM" → '2026-11-14 14:08:00' */
    private static function stamp(string $line): ?string
    {
        if (preg_match('~(?:(\d{1,2})/(\d{1,2})/(\d{4})|(\d{4})-(\d{2})-(\d{2}))\s+-\s+(\d{1,2}:\d{2}\s*[AP]M)\s*$~i', trim($line), $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = $m[1] !== '' ? [(int) $m[3], (int) $m[1], (int) $m[2]] : [(int) $m[4], (int) $m[5], (int) $m[6]];
        $time = MeetProgram::time($m[7]);

        if ($time === null || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %s:00', $year, $month, $day, $time);
    }
}
