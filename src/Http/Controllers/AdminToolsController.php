<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Http\Controller;
use DropTime\Http\Flash;
use DropTime\Http\ProfileSession;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Http\TestClock;
use DropTime\Push\PushRepository;
use DropTime\Push\RaceNotices;
use DropTime\Push\TestNotice;
use DropTime\Push\WebPush;
use DropTime\Repository\FavoriteRepository;
use DropTime\Repository\RacePlanRepository;
use DropTime\Support\Clock;
use DropTime\Support\Lang;
use Throwable;

/**
 * 管理者用のテスト（/admin/tools）。
 *
 *   - 通知: この端末の診断、テスト通知（今すぐ・数秒後・数分後）、登録している端末の一覧、
 *           指定の時刻に送られる予定の知らせの確認、送信の記録
 *   - 画面: スケジュールを指定の時刻で表示する（テスト時刻）
 *   - 稼働: 定期処理（速報の確認・通知）が動いているか、実測の蓄え
 *
 * 到達には管理者の合言葉が必要（Access::ADMIN）。
 */
final class AdminToolsController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->context->db;
        $config = $this->context->config;
        $pushes = new PushRepository($db);
        $subscriptions = $pushes->all();

        // 指定の時刻（無ければ今）に、どの端末へ何が送られるか。本番の定期処理と同じ計算を使う。
        $at = TestClock::parse($request->queryString('at'));
        $now = $at ?? Clock::now()->setTimezone(Clock::localZone())->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $favorites = new FavoriteRepository($db);
        $planner = new RacePlanRepository($db, $config->int('schedule.lanes', 8));
        $leads = array_map('intval', (array) $config->get('push.lead_minutes', [30, 10]));
        $screenLang = Lang::current();
        $plans = [];
        $rows = [];

        foreach ($subscriptions as $subscription) {
            $id = (int) $subscription['id'];
            $profileId = (int) $subscription['profile_id'];
            $due = [];

            try {
                $plans[$profileId] ??= $planner->plan($favorites->upcomingEntries($profileId, $today), $today, $now);
                Lang::init((string) $subscription['lang']);
                $notices = RaceNotices::due($plans[$profileId], $now, $leads);
                Lang::init($screenLang);
                $keys = [];

                foreach ($notices as $notice) {
                    array_push($keys, ...$notice['keys']);
                }

                $sent = array_flip($pushes->sentKeys($id, array_values(array_unique($keys))));

                foreach ($notices as $notice) {
                    $due[] = ['title' => $notice['title'], 'body' => $notice['body'], 'sent' => isset($sent[$notice['keys'][0]])];
                }
            } catch (Throwable) {
                Lang::init($screenLang);
            }

            $rows[] = [
                'id' => $id,
                'profile_id' => $profileId,
                'hash' => (string) $subscription['endpoint_hash'],
                'relay' => TestNotice::relayName((string) $subscription['endpoint']),
                'lang' => (string) $subscription['lang'],
                'created_at' => (string) $subscription['created_at'],
                'last_sent_at' => $subscription['last_sent_at'] === null ? null : (string) $subscription['last_sent_at'],
                'favorites' => (int) $db->selectValue('SELECT COUNT(*) FROM favorites WHERE profile_id = :id', ['id' => $profileId]),
                'sent_count' => (int) $db->selectValue('SELECT COUNT(*) FROM push_sent WHERE subscription_id = :id', ['id' => $id]),
                'mine' => $profileId === ProfileSession::currentId(),
                'due' => $due,
            ];
        }

        return $this->view('admin/tools', [
            'flash' => Flash::pull(),
            'subscriptions' => $rows,
            'vapidPublic' => $this->vapidPublic(),
            'dueAt' => $now,
            'dueAtGiven' => $at !== null,
            'testNow' => TestClock::now(),
            'health' => $this->health(),
            'log' => $this->logTail(APP_ROOT . '/var/logs/push.log', 30),
        ]);
    }

    /** テスト通知を送る（今すぐ、または数秒後・数分後）。 */
    public function push(Request $request): Response
    {
        $id = (int) $request->postString('subscription');
        $kind = $request->postString('kind', 'test');
        $delay = (int) $request->postString('delay', '0');
        $row = $this->context->db->selectOne('SELECT * FROM push_subscriptions WHERE id = :id', ['id' => $id]);

        if ($row === null || !in_array($kind, TestNotice::KINDS, true) || !in_array($delay, TestNotice::DELAYS, true)) {
            Flash::error(Lang::t('tools.push_no_target'));

            return $this->redirectToRoute('admin.tools');
        }

        if ($delay > 0) {
            if (TestNotice::sendLater($id, $kind, $delay)) {
                Flash::success(Lang::t('tools.push_scheduled', $id, $delay));
            } else {
                Flash::error(Lang::t('tools.push_cannot_schedule'));
            }

            return $this->redirectToRoute('admin.tools');
        }

        try {
            $status = TestNotice::send($row, $kind);
        } catch (Throwable) {
            $status = 0;
        }

        TestNotice::log(sprintf('テスト送信 購読 %d（%s・今すぐ）: 中継サーバーの返事 HTTP %d', $id, $kind, $status));

        if ($status >= 200 && $status < 300) {
            Flash::success(Lang::t('tools.push_sent', $id, $status));
        } elseif ($status === 404 || $status === 410) {
            Flash::error(Lang::t('tools.push_gone', $id, $status));
        } else {
            Flash::error(Lang::t('tools.push_failed', $id, $status));
        }

        return $this->redirectToRoute('admin.tools');
    }

    /** 使われなくなった端末の登録を消す。 */
    public function pushDelete(Request $request): Response
    {
        $id = (int) $request->postString('subscription');

        if ($id > 0) {
            (new PushRepository($this->context->db))->delete($id);
            Flash::success(Lang::t('tools.sub_deleted', $id));
        }

        return $this->redirectToRoute('admin.tools');
    }

    /** スケジュールの画面のテスト時刻を設定・解除する。 */
    public function clock(Request $request): Response
    {
        if ($request->postString('clear') !== '') {
            TestClock::clear();
            Flash::success(Lang::t('tools.clock_cleared'));
        } elseif (TestClock::set($request->postString('at'))) {
            Flash::success(Lang::t('tools.clock_set', (string) TestClock::now()));

            return $this->redirectToRoute('home');
        } else {
            Flash::error(Lang::t('tools.clock_invalid'));
        }

        return $this->redirectToRoute($request->postString('back') === 'home' ? 'home' : 'admin.tools');
    }

    /**
     * 定期処理が動いているか・実測の蓄え。
     *
     * @return array{live: ?string, push: ?string, timings: int, timing_meets: int, finished_today: int, vapid: bool, exec: bool}
     */
    private function health(): array
    {
        $db = $this->context->db;
        $beat = static function (string $file): ?string {
            $time = @filemtime(APP_ROOT . '/var/logs/' . $file);

            return $time === false ? null : (new \DateTimeImmutable('@' . $time))->setTimezone(Clock::localZone())->format('Y-m-d H:i:s');
        };
        $count = static function (string $sql, array $params = []) use ($db): int {
            try {
                return (int) $db->selectValue($sql, $params);
            } catch (Throwable) {
                return 0;
            }
        };

        return [
            'live' => $beat('live-progress.last'),
            'push' => $beat('push-notify.last'),
            'timings' => $count('SELECT COUNT(*) FROM event_timings'),
            'timing_meets' => $count('SELECT COUNT(DISTINCT meet_id) FROM event_timings'),
            'finished_today' => $count('SELECT COUNT(*) FROM live_event_status WHERE finished_at >= :today', ['today' => Clock::localToday() . ' 00:00:00']),
            'vapid' => $this->vapidPublic() !== '',
            'exec' => function_exists('exec'),
        ];
    }

    /** @return list<string> */
    private function logTail(string $path, int $lines): array
    {
        $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $all === false ? [] : array_slice($all, -$lines);
    }

    private function vapidPublic(): string
    {
        $pem = $this->context->config->string('push.vapid_private', '');

        if ($pem === '') {
            return '';
        }

        try {
            return (new WebPush($pem, ''))->publicKey();
        } catch (Throwable) {
            return '';
        }
    }
}
