<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Kv\CounterStore;
use App\Core\Kv\MysqlCounter;
use App\Core\Kv\RedisCounter;
use App\Core\Kv\RespClient;

/**
 * KV/限流驱动入口。
 *
 * 配置了 REDIS_URL 时使用纯 PHP RESP 客户端 + RedisCounter，实现多实例共享限流；
 * 否则回退到现有 throttle 表的 MySQL 实现，单机部署行为保持不变。
 */
final class Kv
{
    private static ?CounterStore $counter = null;

    public static function counter(): CounterStore
    {
        if (self::$counter !== null) {
            return self::$counter;
        }

        $redisUrl = trim((string) config('kv.redis_url', ''));
        self::$counter = $redisUrl !== ''
            ? new RedisCounter(new RespClient($redisUrl))
            : new MysqlCounter();

        return self::$counter;
    }

    /**
     * 测试或特殊部署下的显式注入点；传 null 清除缓存，下次按配置重新选择驱动。
     */
    public static function setCounter(?CounterStore $counter): void
    {
        self::$counter = $counter;
    }

    public static function reset(): void
    {
        self::$counter = null;
    }
}
