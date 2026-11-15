<?php

declare(strict_types=1);

namespace DropTime\Push;

use DropTime\Domain\EventTitle;
use DropTime\Domain\LiveSchedule;
use DropTime\Repository\RacePlanRepository;
use DropTime\Support\Lang;

/**
 * いま送るべき通知を選ぶ（送るのは bin/push-notify.php）。DB にも通信にも触らない。
 *
 * 2 種類:
 *   1. 今日の出番（朝 1 回）: 「9:15〜15:40 ごろ（3 種目）」。会場にいる時間帯の目安。
 *      7 時を過ぎたら、または最初の種目まで 2 時間を切ったら送る。最後の種目が終わっていれば送らない。
 *   2. 出番が近い: 見積もりの開始まで lead 分（既定 30 分・10 分）を切ったら、種目ごとに段階ごと 1 回。
 *      cron の間隔より遅れて 10 分を切った場合は、30 分の知らせは飛ばして 10 分の知らせだけ送る。
 *
 * 文言は呼び出し側で Lang::init() した言語になる。
 */
final class RaceNotices
{
    private const DAY_NOTICE_HOUR = 7;

    private const DAY_NOTICE_BEFORE_MINUTES = 120;

    /**
     * @param list<array<string, mixed>> $groups RacePlanRepository::plan() の戻り値
     * @param string $now 現地の今 'Y-m-d H:i:s'
     * @param list<int> $leads 何分前に知らせるか
     * @return list<array{keys: list<string>, skip_if?: list<string>, title: string, body: string, url: string, tag: string, ttl: int}>
     *         keys の先頭が送り済みかの判定に使うキー。残りは一緒に送り済みにするキー。
     */
    public static function due(array $groups, string $now, array $leads, string $basePath = ''): array
    {
        rsort($leads);
        $today = substr($now, 0, 10);
        $notices = [];

        foreach (RacePlanRepository::todayWindows($groups) as $window) {
            $minutesToFirst = LiveSchedule::minutesUntil((string) $window['first']['today_at'], $now);
            $lastDone = ($window['last']['live']['status'] ?? null) === LiveSchedule::DONE;

            if ($lastDone || ((int) substr($now, 11, 2) < self::DAY_NOTICE_HOUR && $minutesToFirst > self::DAY_NOTICE_BEFORE_MINUTES)) {
                continue;
            }

            $range = $window['count'] === 1
                ? Lang::t('push.day_one', $window['first']['clock'])
                : Lang::t('push.day_range', $window['first']['clock'], $window['last']['clock'], $window['count']);

            $notices[] = [
                'keys' => ['day|' . $today . '|' . $window['swimmer_id'] . '|' . (int) $window['meet']['id']],
                'title' => Lang::t('push.day_title', $window['swimmer_name']),
                'body' => $range . "\n" . $window['meet']['name'],
                'url' => $basePath . '/',
                'tag' => 'day-' . $window['swimmer_id'],
                'ttl' => 3 * 3600,
            ];
        }

        foreach ($groups as $group) {
            foreach ($group['entries'] as $entry) {
                $at = $entry['today_at'] ?? null;

                if ($at === null) {
                    continue;
                }

                $status = $entry['live']['status'] ?? ($at > $now ? LiveSchedule::UPCOMING : LiveSchedule::RUNNING);

                if ($status !== LiveSchedule::UPCOMING) {
                    continue;
                }

                $minutes = LiveSchedule::minutesUntil((string) $at, $now);
                $stage = null;

                foreach ($leads as $lead) {
                    if ($minutes <= $lead) {
                        $stage = $lead; // 小さい段階ほど後に来るので上書きされる
                    }
                }

                if ($stage === null) {
                    continue;
                }

                $base = 'race|' . $today . '|' . (int) $entry['swimmer_id'] . '|' . (int) $entry['event_id'] . '|';
                $keys = [$base . $stage];

                foreach ($leads as $lead) {
                    if ($lead > $stage) {
                        $keys[] = $base . $lead;
                    }
                }

                // もっと近い段階（10 分）を送り済みなら、遠い段階（30 分）はもう送らない。
                $closer = array_map(static fn (int $lead): string => $base . $lead, array_values(array_filter($leads, static fn (int $lead): bool => $lead < $stage)));

                $when = $minutes <= 5 ? Lang::t('home.cd_soon') : Lang::t('home.cd_minutes', $minutes);
                $place = [];

                if (($entry['heat'] ?? null) !== null) {
                    $place[] = Lang::t('common.heat') . ' ' . $entry['heat'];
                }

                if (($entry['lane'] ?? null) !== null) {
                    $place[] = Lang::t('common.lane') . ' ' . $entry['lane'];
                }

                $notices[] = [
                    'keys' => $keys,
                    'skip_if' => $closer,
                    'title' => Lang::t('push.race_title', (string) $entry['swimmer_name'], $when),
                    'body' => EventTitle::withNumber($entry) . "\n"
                        . Lang::t('home.cd_at', RacePlanRepository::clock((string) $at))
                        . ($place === [] ? '' : ' ・ ' . implode(' ', $place)),
                    'url' => $basePath . '/',
                    'tag' => 'race-' . (int) $entry['swimmer_id'] . '-' . (int) $entry['event_id'],
                    'ttl' => max(60, $minutes * 60),
                ];
            }
        }

        return $notices;
    }
}
