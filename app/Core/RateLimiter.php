<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /**
     * 记录一次计数；返回 true 表示仍然允许，false 表示已超限。
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = hash('sha256', $key);
        $now = time();

        $row = Db::fetch('SELECT hits, reset_at FROM throttle WHERE throttle_key = ?', [$key]);
        if ($row === null || strtotime((string) $row['reset_at']) <= $now) {
            Db::query(
                'REPLACE INTO throttle (throttle_key, hits, reset_at) VALUES (?, 1, ?)',
                [$key, date('Y-m-d H:i:s', $now + $windowSeconds)]
            );
            return true;
        }

        if ((int) $row['hits'] >= $max) {
            return false;
        }

        Db::query('UPDATE throttle SET hits = hits + 1 WHERE throttle_key = ?', [$key]);
        return true;
    }

    public static function blocked(string $key, int $max): bool
    {
        $key = hash('sha256', $key);
        $row = Db::fetch('SELECT hits, reset_at FROM throttle WHERE throttle_key = ?', [$key]);
        if ($row === null || strtotime((string) $row['reset_at']) <= time()) {
            return false;
        }
        return (int) $row['hits'] >= $max;
    }

    public static function clear(string $key): void
    {
        Db::query('DELETE FROM throttle WHERE throttle_key = ?', [hash('sha256', $key)]);
    }
}
