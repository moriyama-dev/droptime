<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\LatestComparison;
use DropTime\Domain\LiveSchedule;
use DropTime\Domain\Progression;
use DropTime\Http\Controller;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Push\WebPush;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\RacePlanRepository;
use DropTime\Repository\SwimmerRepository;
use DropTime\Support\Clock;

/**
 * 保護者向けのトップ。最上位の情報は「次に泳ぐレース」。
 * プールサイドで「うちの子、次いつ？」に 3 秒で答えるための画面。
 */
final class HomeController extends Controller
{
    /** 「この大会の泳ぎ」に並べる数（残りは成績タブで見る）。 */
    private const MEET_SWIMS_SHOWN = 3;

    public function index(Request $request): Response
    {
        $favorites = new FavoriteRepository($this->context->db);
        $profileId = ProfileSession::currentId();

        $favoriteSwimmers = $profileId !== null ? $favorites->favoritesOf($profileId) : [];

        if ($favoriteSwimmers === []) {
            return $this->view('parent/home', [
                'favoriteSwimmers' => [],
                'meetGroups' => [],
                'meetSwims' => [],
                'todayWindows' => [],
                'recentResults' => [],
                'hasMeets' => $this->hasAnyMeet(),
                'vapidPublic' => $this->vapidPublic(),
            ]);
        }

        $today = Clock::localToday();
        $now = Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d H:i:s');
        $planner = new RacePlanRepository($this->context->db, $this->context->config->int('schedule.lanes', 8));
        $planned = $planner->plan($favorites->upcomingEntries($profileId, $today), $today, $now);
        $todayWindows = RacePlanRepository::todayWindows($planned);
        $meetGroups = $this->withoutFinished($planned);
        $meetSwims = $this->meetSwims($favoriteSwimmers);
        $meetDay = $meetSwims !== [] || array_filter($meetGroups, static fn (array $group): bool => $group['is_today']) !== [];

        return $this->view('parent/home', [
            'favoriteSwimmers' => $favoriteSwimmers,
            'meetGroups' => $meetGroups,
            'meetSwims' => $meetSwims,
            'todayWindows' => $todayWindows,
            'recentResults' => $favorites->recentResults($profileId, 8),
            'hasMeets' => true,
            'vapidPublic' => $this->vapidPublic(),
            // 大会の当日は、開いている間 1 分おきに表示を新しくする（カウントダウンと速報の補正）。
            'refreshSeconds' => $meetDay ? 60 : 0,
        ]);
    }

    /**
     * 泳ぎ終わった種目は「最近の結果」に出るので、「次に泳ぐレース」には並べない。
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private function withoutFinished(array $groups): array
    {
        $kept = [];

        foreach ($groups as $group) {
            $group['entries'] = array_values(array_filter(
                $group['entries'],
                static fn (array $entry): bool => ($entry['live']['status'] ?? null) !== LiveSchedule::DONE
            ));

            if ($group['entries'] !== []) {
                $kept[] = $group;
            }
        }

        return $kept;
    }

    /**
     * 大会の会期中、お気に入りの選手がその大会で泳いだ結果と「1 つ前」の比較（成績タブの ① と同じ）。
     * 結果が入るとホームのいちばん上に出る。縮めた種目が先に並ぶ。
     *
     * @param list<array<string, mixed>> $favoriteSwimmers
     * @return list<array{swimmer: array<string, mixed>, comparison: array<string, mixed>, more: int}>
     */
    private function meetSwims(array $favoriteSwimmers): array
    {
        $today = Clock::localToday();
        $meetIds = array_map('intval', array_column($this->context->db->select(
            'SELECT id FROM meets WHERE start_date <= :today AND COALESCE(end_date, start_date) >= :today2',
            ['today' => $today, 'today2' => $today]
        ), 'id'));

        if ($meetIds === []) {
            return [];
        }

        $swimmers = new SwimmerRepository($this->context->db);
        $cards = [];

        foreach ($favoriteSwimmers as $favorite) {
            // 正式な取り込みを待たずに、本番が速報サイトから直接読んだ結果も足す。
            $results = [...$swimmers->resultsOf((int) $favorite['id']), ...$swimmers->liveResultsOf((int) $favorite['id'])];
            $shapes = null;

            foreach ($meetIds as $meetId) {
                if (!in_array($meetId, array_map('intval', array_column($results, 'meet_id')), true)) {
                    continue;
                }

                $shapes ??= Progression::build($results);
                $comparison = LatestComparison::of($shapes, $meetId);

                if ($comparison === null || $comparison['races'] === []) {
                    continue;
                }

                $more = max(0, count($comparison['races']) - self::MEET_SWIMS_SHOWN);
                $comparison['races'] = array_slice($comparison['races'], 0, self::MEET_SWIMS_SHOWN);
                $cards[] = ['swimmer' => $favorite, 'comparison' => $comparison, 'more' => $more];
            }
        }

        return $cards;
    }

    /** 通知の購読に使う公開鍵。設定が無ければ空（通知の欄を出さない）。 */
    private function vapidPublic(): string
    {
        $pem = $this->context->config->string('push.vapid_private', '');

        if ($pem === '') {
            return '';
        }

        try {
            return (new WebPush($pem, ''))->publicKey();
        } catch (\Throwable) {
            return '';
        }
    }

    private function hasAnyMeet(): bool
    {
        return $this->context->db->selectValue('SELECT 1 FROM meets LIMIT 1') !== null;
    }
}
