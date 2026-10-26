<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

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

        return $this->view('parent/home', [
            'favoriteSwimmers' => $favoriteSwimmers,
            'meetGroups' => $meetGroups,
            'recentResults' => $favorites->recentResults($profileId, 8),
            'hasMeets' => true,
        ]);
    }

    /**
     * エントリーを大会ごとにまとめ、推定開始時刻を付ける。
     *
     * 推定は大会プログラム全体（お気に入り以外の種目も含む）の積算で行う。
     * 自分の種目だけを足しても時刻にならないため。
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{meet: array<string, mixed>, entries: list<array<string, mixed>>}>
     */
    private function groupByMeet(array $entries): array
    {
        $meets = new MeetRepository($this->context->db);
        $groups = [];

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

                $groups[$meetId] = [
                    'meet' => [
                        'id' => $meetId,
                        'name' => $entry['meet_name'],
                        'start_date' => $entry['start_date'],
                        'end_date' => $entry['end_date'],
                        'venue' => $entry['venue'],
                    ],
                    'estimates' => $estimates,
                    'entries' => [],
                ];
            }

            $entry['schedule'] = $groups[$meetId]['estimates'][(int) $entry['event_id']] ?? null;
            $groups[$meetId]['entries'][] = $entry;
        }

        return array_values(array_map(static function (array $group): array {
            unset($group['estimates']);

            return $group;
        }, $groups));
    }

    private function hasAnyMeet(): bool
    {
        return $this->context->db->selectValue('SELECT 1 FROM meets LIMIT 1') !== null;
    }
}
