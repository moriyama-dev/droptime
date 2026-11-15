<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\LiveSchedule;
use DropTime\Http\Controller;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Push\WebPush;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\RacePlanRepository;
use DropTime\Support\Clock;

/**
 * 保護者向けのトップ（下部タブの「スケジュール」）。お気に入りの選手の今日と、この先の予定だけを出す。
 * 過去の記録は「成績」「履歴」のタブで見る（2026-11-15 Yoshi の指示）。
 * プールサイドで「うちの子、次いつ？」「何時から何時まで会場にいればいい？」に 3 秒で答えるための画面。
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
                'todayWindows' => [],
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
        $meetDay = $todayWindows !== [] || array_filter($meetGroups, static fn (array $group): bool => $group['is_today']) !== [];

        return $this->view('parent/home', [
            'favoriteSwimmers' => $favoriteSwimmers,
            'meetGroups' => $meetGroups,
            'todayWindows' => $todayWindows,
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
