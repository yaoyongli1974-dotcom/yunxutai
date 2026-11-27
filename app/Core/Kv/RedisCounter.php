<?php
declare(strict_types=1);

namespace App\Core\Kv;

/**
 * Redis 计数存储：INCR + 首次 EXPIRE。
 *
 * 只在 INCR 返回 1（新窗口第一次命中）时设置过期，避免每次 hit 都续期，
 * 使窗口保持固定长度而不是「最后一次访问后顺延」。
 */
final class RedisCounter implements CounterStore
{
    private RespClient $client;
    private string $prefix;

    public function __construct(RespClient $client, string $prefix = 'jingsi:throttle:')
    {
        $this->client = $client;
        $this->prefix = $prefix;
    }

    public function increment(string $key, int $windowSeconds): int
    {
        $fullKey = $this->prefix . $key;
        $hits = (int) $this->client->command('INCR', $fullKey);
        if ($hits === 1) {
            $this->client->command('EXPIRE', $fullKey, (string) $windowSeconds);
        }
        return $hits;
    }

    public function consume(string $key, int $max, int $windowSeconds): bool
    {
        $script = <<<'LUA'
local current = tonumber(redis.call('GET', KEYS[1]) or '0')
local limit = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
if current >= limit then
  return current + 1
end
if current == 0 then
  redis.call('SET', KEYS[1], 1, 'EX', ttl)
  return 1
end
return redis.call('INCR', KEYS[1])
LUA;
        $result = (int) $this->client->command('EVAL', $script, '1', $this->prefix . $key, (string) $max, (string) $windowSeconds);
        return $result <= $max;
    }

    public function count(string $key): int
    {
        $value = $this->client->command('GET', $this->prefix . $key);
        return $value === null ? 0 : (int) $value;
    }

    public function ttl(string $key): int
    {
        // Redis 对不存在的键返回 -2，对无过期时间的键返回 -1；对外统一为 -1=不存在/不可用。
        $ttl = (int) $this->client->command('TTL', $this->prefix . $key);
        return $ttl < 0 ? -1 : $ttl;
    }

    public function reset(string $key): void
    {
        $this->client->command('DEL', $this->prefix . $key);
    }
}
