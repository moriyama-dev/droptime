<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\LiveSchedule;
use DropTime\Domain\ScheduleEstimator;
use DropTime\Http\Controller;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\MeetRepository;
use DropTime\Support\Clock;

/**
 * 保護者向けのトップ。最上位の情報は「次に泳ぐレース」。
 * プールサイドで「うちの子、次いつ？」に 3 秒で答えるための画面。
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $favorites = new FavoriteRepository($this->context->db);
        $profileId = ProfileSession::currentId();

        $favoriteSwimmers = $profileId !== null ? $favorites->favoritesOf($profileId) : [];

        if ($favoriteSwimmers === []) {
            return $this->view('parent/home', [
                'favoriteSwimmers' => [],
                'meetGroups' => [],
                'recentResults' => [],
                'hasMeets' => $this->hasAnyMeet(),
            ]);
        }

        $upcoming = $favorites->upcomingEntries($profileId, Clock::localToday());
        $meetGroups = $this->groupByMeet($upcoming);
        $meetDay = array_filter($meetGroups, static fn (array $group): bool => $group['is_today']) !== [];

        return $this->view('parent/home', [
            'favoriteSwimmers' => $favoriteSwimmers,
            'meetGroups' => $meetGroups,
            'recentResults' => $favorites->recentResults($profileId, 8),
            'hasMeets' => true,
            // 大会の当日は、開いている間 1 分おきに表示を新しくする（カウントダウンと速報の補正）。
            'refreshSeconds' => $meetDay ? 60 : 0,
        ]);
    }

    /**
     * エントリーを大会ごとにまとめ、推定開始時刻を付ける。
     *
     * 推定は大会プログラム全体（お気に入り以外の種目も含む）の積算で行う。
     * 自分の種目だけを足しても時刻にならないため。
     *
     * 速報サイトの目次からセッション（開始時刻と種目の並び）が分かっている大会は、
     * LiveSchedule でセッションごとに見積もり、当日は速報の終わった時刻で補正する。
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{meet: array<string, mixed>, entries: list<array<string, mixed>>, is_today: bool, latest: ?array<string, mixed>}>
     */
    private function groupByMeet(array $entries): array
    {
        $meets = new MeetRepository($this->context->db);
        $groups = [];
        $today = Clock::localToday();
        $now = Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d H:i:s');
        $lanes = $this->context->config->int('schedule.lanes', 8);

        foreach ($entries as $entry) {
            $meetId = (int) $entry['meet_id'];

            if (!isset($groups[$meetId])) {
                $program = $meets->programOf($meetId);
                $estimates = ScheduleEstimator::estimate(array_map(
                    static fn (array $event): array => [
                        'id' => (int) $event['id'],
                        'distance' => (int) $event['distance'],
                        'heats' => (int) $event['heat_count'],
                        'scheduled_time' => is_string($event['scheduled_time']) ? $event['scheduled_time'] : null,
                    ],
                    $program
                ));

                $sessions = $meets->sessionsOf($meetId);
                $live = $sessions === [] ? [] : $meets->liveStatusOf($meetId);
                $end = (string) ($entry['end_date'] ?? $entry['start_date']);

                $groups[$meetId] = [
                    'meet' => [
                        'id' => $meetId,
                        'name' => $entry['meet_name'],
                        'start_date' => $entry['start_date'],
                        'end_date' => $entry['end_date'],
                        'venue' => $entry['venue'],
                    ],
                    'estimates' => $estimates,
                    'plan' => $sessions === [] ? [] : LiveSchedule::forMeet($sessions, $live, $now, $lanes),
                    'is_today' => (string) $entry['start_date'] <= $today && $end >= $today,
                    'latest' => $live === [] ? null : $live[0] + ['time' => substr($live[0]['finished_at'], 11, 5)],
                    'entries' => [],
                ];
            }

            $entry['schedule'] = $groups[$meetId]['estimates'][(int) $entry['event_id']] ?? null;
            $entry['live'] = null;
            $plan = $groups[$meetId]['plan'][LiveSchedule::key((string) $entry['event_number'], (string) $entry['round'])] ?? null;

            if ($plan !== null) {
                $entry['live'] = $plan + [
                    'date' => substr($plan['start'], 0, 10),
                    'time' => ltrim(substr($plan['start'], 11, 5), '0'),
                    'minutes' => LiveSchedule::minutesUntil($plan['start'], $now),
                    'is_today' => substr($plan['start'], 0, 10) === $today,
                ];
                $entry['schedule'] = ['time' => $entry['live']['time'], 'estimated' => true];
            }

            // 泳ぎ終わった種目は「最近の結果」に出るので、「次に泳ぐレース」には並べない。
            if (($entry['live']['status'] ?? null) === LiveSchedule::DONE) {
                continue;
            }

            $groups[$meetId]['entries'][] = $entry;
        }

        $groups = array_filter($groups, static fn (array $group): bool => $group['entries'] !== []);

        return array_values(array_map(static function (array $group): array {
            unset($group['estimates'], $group['plan']);

            // セッションの見積もりがある大会は、見積もりの開始時刻の順に並べる（並行するセッションが混ざるため）。
            usort($group['entries'], static fn (array $a, array $b): int => strcmp(
                (string) ($a['live']['start'] ?? ''),
                (string) ($b['live']['start'] ?? '')
            ));

            return $group;
        }, $groups));
    }

    private function hasAnyMeet(): bool
    {
        return $this->context->db->selectValue('SELECT 1 FROM meets LIMIT 1') !== null;
    }
}
