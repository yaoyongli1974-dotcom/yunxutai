<?php
declare(strict_types=1);

namespace App\Core\Kv;

use App\Core\Db;

/**
 * MySQL 计数存储：直接复用现有 throttle 表。
 *
 * 行为与改造前的 RateLimiter 完全一致：reset_at 为绝对过期时间，
 * 判断过期时统一用 strtotime 解析 DATETIME，便于从旧版本滚动迁移。
 */
final class MysqlCounter implements CounterStore
{
    public function increment(string $key, int $windowSeconds): int
    {
        $now = time();
        $row = Db::fetch('SELECT hits, reset_at FROM throttle WHERE throttle_key = ?', [$key]);

        if ($row === null || strtotime((string) $row['reset_at']) <= $now) {
            Db::query(
                'REPLACE INTO throttle (throttle_key, hits, reset_at) VALUES (?, 1, ?)',
                [$key, date('Y-m-d H:i:s', $now + $windowSeconds)]
            );
            return 1;
        }

        Db::query('UPDATE throttle SET hits = hits + 1 WHERE throttle_key = ?', [$key]);
        return (int) $row['hits'] + 1;
    }

    public function count(string $key): int
    {
        $row = Db::fetch('SELECT hits, reset_at FROM throttle WHERE throttle_key = ?', [$key]);
        if ($row === null || strtotime((string) $row['reset_at']) <= time()) {
            return 0;
        }
        return (int) $row['hits'];
    }

    public function ttl(string $key): int
    {
        $row = Db::fetch('SELECT reset_at FROM throttle WHERE throttle_key = ?', [$key]);
        if ($row === null) {
            return -1;
        }
        $remaining = strtotime((string) $row['reset_at']) - time();
        return $remaining > 0 ? $remaining : -1;
    }

    public function reset(string $key): void
    {
        Db::query('DELETE FROM throttle WHERE throttle_key = ?', [$key]);
    }
}
