<?php
declare(strict_types=1);

namespace App\Core\Kv;

/**
 * 限流计数存储抽象。
 *
 * key 语义与 RateLimiter 保持一致：调用方负责 hash('sha256', $key)，
 * 驱动不再做哈希，只做带命名空间的键值操作。
 */
interface CounterStore
{
    /**
     * 计数加一并返回当前值；首次命中返回 1，并开始 windowSeconds 窗口。
     */
    public function increment(string $key, int $windowSeconds): int;

    /**
     * 读取当前窗口计数；未命中或已过期返回 0。
     */
    public function count(string $key): int;

    /**
     * 剩余秒数；键不存在或已过期返回 -1。
     */
    public function ttl(string $key): int;

    /**
     * 清空计数与窗口。
     */
    public function reset(string $key): void;
}
