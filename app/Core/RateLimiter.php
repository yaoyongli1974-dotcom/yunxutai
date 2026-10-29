<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /**
     * 记录一次计数；返回 true 表示仍然允许，false 表示已超限。
     *
     * 公开 API 与语义保持与 MySQL throttle 版本一致：已超限时不再累加计数。
     * 存储驱动由 Kv::counter() 决定（Redis 或 MySQL），哈希仍由本类统一完成。
     */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = hash('sha256', $key);
        $counter = Kv::counter();

        if ($counter->count($key) >= $max) {
            return false;
        }

        return $counter->increment($key, $windowSeconds) <= $max;
    }

    public static function blocked(string $key, int $max): bool
    {
        return Kv::counter()->count(hash('sha256', $key)) >= $max;
    }

    public static function clear(string $key): void
    {
        Kv::counter()->reset(hash('sha256', $key));
    }
}
