<?php

declare(strict_types=1);

namespace DropTime\Fetch;

use DropTime\Db\Database;
use DropTime\Import\HtmlTextExtractor;
use DropTime\Parser\HyTek\HyTekLine;
use DropTime\Support\Clock;
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
 */
final class LiveProgress
{
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
     * @return array{event: ?string, round: ?string, finished_at: ?string, note: string}
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

        return $parsed + ['note' => '記録しました'];
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
