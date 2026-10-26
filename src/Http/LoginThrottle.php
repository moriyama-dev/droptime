<?php

declare(strict_types=1);

namespace DropTime\Http;

use DropTime\Db\Database;
use DropTime\Support\Clock;

/**
 * ログインのブルートフォース対策。
 *
 * IP ごとに、直近 window 分の失敗回数が上限に達したら lock 分ロックする。
 * 判定に使う時刻は epoch 秒なので、MySQL の time_zone 設定に左右されない。
 */
final class LoginThrottle
{
    public const SCOPE_PARENT = 'parent';
    public const SCOPE_ADMIN = 'admin';

    /** 古い試行履歴を掃除する確率の分母。 */
    private const PURGE_ODDS = 100;

    public function __construct(
        private readonly Database $db,
        private readonly int $maxAttempts,
        private readonly int $windowMinutes,
        private readonly int $lockMinutes,
    ) {
    }

    /**
     * ロック中なら解除までの残り秒数、そうでなければ 0 を返す。
     */
    public function lockedSeconds(string $ipBinary, string $scope): int
    {
        $now = Clock::timestamp();

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_at'
            . ' FROM login_attempts'
            . ' WHERE ip = :ip AND scope = :scope AND succeeded = 0 AND attempted_at >= :since',
            [
                'ip' => $ipBinary,
                'scope' => $scope,
                'since' => $now - $this->windowMinutes * 60,
            ]
        );

        $failures = (int) ($row['failures'] ?? 0);
        $lastAt = isset($row['last_at']) && $row['last_at'] !== null ? (int) $row['last_at'] : null;

        return self::lockSecondsRemaining($failures, $lastAt, $now, $this->maxAttempts, $this->lockMinutes);
    }

    /**
     * ロック判定そのもの。DB に触れないので単体で検証できる。
     */
    public static function lockSecondsRemaining(
        int $failures,
        ?int $lastFailureAt,
        int $now,
        int $maxAttempts,
        int $lockMinutes,
    ): int {
        if ($failures < $maxAttempts || $lastFailureAt === null) {
            return 0;
        }

        $unlockAt = $lastFailureAt + $lockMinutes * 60;

        return max(0, $unlockAt - $now);
    }

    public function recordFailure(string $ipBinary, string $scope): void
    {
        $this->record($ipBinary, $scope, false);
    }

    /**
     * 成功したら、その IP の失敗履歴を消してカウンタを戻す。
     */
    public function recordSuccess(string $ipBinary, string $scope): void
    {
        $this->record($ipBinary, $scope, true);

        $this->db->execute(
            'DELETE FROM login_attempts WHERE ip = :ip AND scope = :scope AND succeeded = 0',
            ['ip' => $ipBinary, 'scope' => $scope]
        );
    }

    private function record(string $ipBinary, string $scope, bool $succeeded): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (ip, scope, succeeded, attempted_at)'
            . ' VALUES (:ip, :scope, :succeeded, :attempted_at)',
            [
                'ip' => $ipBinary,
                'scope' => $scope,
                'succeeded' => $succeeded ? 1 : 0,
                'attempted_at' => Clock::timestamp(),
            ]
        );

        $this->purgeOccasionally();
    }

    /**
     * 常駐プロセスも cron も使えない前提なので、確率的に掃除する。
     */
    private function purgeOccasionally(): void
    {
        if (random_int(1, self::PURGE_ODDS) !== 1) {
            return;
        }

        $this->db->execute(
            'DELETE FROM login_attempts WHERE attempted_at < :threshold',
            ['threshold' => Clock::timestamp() - 30 * 86400]
        );
    }
}
