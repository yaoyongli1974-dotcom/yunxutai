<?php
declare(strict_types=1);

namespace App\Core\Kv;

use SessionHandlerInterface;

/**
 * Redis 会话存储：SETEX / GET / DEL。
 *
 * 会话数据由 PHP session 模块序列化后原样存入 Redis；
 * TTL 跟随 session.gc_maxlifetime，保证与文件会话的回收节奏一致。
 * open/close/gc 均为无副作用实现（Redis 已通过 TTL 自动清理）。
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private RespClient $client;
    private string $prefix;

    public function __construct(?RespClient $client = null, string $prefix = 'jingsi:session:')
    {
        if ($client === null) {
            $url = trim((string) config('kv.redis_url', ''));
            if ($url === '') {
                throw new \InvalidArgumentException('未配置 REDIS_URL，无法启用 Redis 会话');
            }
            $client = new RespClient($url);
        }

        $this->client = $client;
        $this->prefix = $prefix;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $value = $this->client->command('GET', $this->key($id));
        return $value === null ? '' : (string) $value;
    }

    public function write(string $id, string $data): bool
    {
        // 每次都读取当前 ini，确保运行期调整 gc_maxlifetime 后立即生效。
        $ttl = max(1, (int) (ini_get('session.gc_maxlifetime') ?: 1440));
        $this->client->command('SETEX', $this->key($id), (string) $ttl, $data);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->client->command('DEL', $this->key($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis 键自带 TTL，无需额外扫描。
        return 0;
    }

    private function key(string $id): string
    {
        return $this->prefix . $id;
    }
}
