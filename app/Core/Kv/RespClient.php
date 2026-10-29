<?php
declare(strict_types=1);

namespace App\Core\Kv;

use Closure;
use RuntimeException;

/**
 * 最小 RESP（Redis 协议）客户端。
 *
 * 仅覆盖项目所需的同步命令，使用 stream socket，不依赖 phpredis 扩展：
 *   redis://[:password@]host[:port][/db]
 *
 * 协议层错误（服务端返回 -ERR）抛 RuntimeException；
 * 连接失败、IO 失败、超时统一抛 KvClientException。
 * 所有命令均按 RESP 数组逐段写入 "长度 + CRLF + 负载 + CRLF"，避免 CRLF 注入。
 */
final class RespClient
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    /** @var Closure|null 进程内协作钩子：测试 mock server / 特殊事件循环使用，生产环境为空 */
    private ?Closure $idleHook;

    private string $host;
    private int $port;
    private int $database;
    private string $password;
    private bool $tls;
    private float $timeout;

    /**
     * @param callable|null $idleHook 读取阻塞时触发的钩子，用于同进程 mock server 推进协议
     */
    public function __construct(string $url, ?callable $idleHook = null, float $timeout = 2.0)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new KvClientException('REDIS_URL 格式无效，应为 redis://[:password@]host[:port][/db]');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'redis' && $scheme !== 'rediss') {
            throw new KvClientException('REDIS_URL 仅支持 redis:// 或 rediss:// 协议');
        }

        $host = (string) $parts['host'];
        if ($host === '') {
            throw new KvClientException('REDIS_URL 缺少主机名');
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $database = $path === '' ? 0 : (int) $path;
        if ($database < 0) {
            throw new KvClientException('REDIS_URL 数据库编号不能为负数');
        }

        // 兼容 redis://:password@host 与 redis://password@host 两种常见写法。
        $password = (string) ($parts['pass'] ?? ($parts['user'] ?? ''));

        $this->host = $host;
        $this->port = (int) ($parts['port'] ?? 6379);
        $this->database = $database;
        $this->password = rawurldecode($password);
        $this->tls = $scheme === 'rediss';
        $this->timeout = $timeout > 0 ? $timeout : 2.0;
        $this->idleHook = $idleHook === null ? null : Closure::fromCallable($idleHook);
    }

    /**
     * 发送一条 RESP 命令并解析响应。
     *
     * @return int|string|array<int,mixed>|null
     */
    public function command(string ...$args): mixed
    {
        if ($args === []) {
            throw new \InvalidArgumentException('RESP 命令不能为空');
        }

        $this->connect();

        $deadline = microtime(true) + $this->timeout;
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        $this->writeAll($payload, $deadline);
        return $this->readReply($deadline);
    }

    /**
     * 主动关闭连接（会话处理器与测试清理时使用）。
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $remote = sprintf(
            '%s%s:%d',
            $this->tls ? 'ssl://' : 'tcp://',
            $this->host,
            $this->port
        );

        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if (!is_resource($socket)) {
            throw new KvClientException(sprintf(
                'Redis 连接失败（%d：%s）',
                (int) $errno,
                $errstr !== '' ? $errstr : '未知错误'
            ));
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->buffer = '';

        try {
            if ($this->password !== '') {
                $this->command('AUTH', $this->password);
            }
            if ($this->database > 0) {
                $this->command('SELECT', (string) $this->database);
            }
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    private function writeAll(string $payload, float $deadline): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($payload, $offset));
            if ($written === false) {
                throw new KvClientException('Redis 写入失败');
            }
            if ($written === 0) {
                if (feof($this->socket)) {
                    throw new KvClientException('Redis 连接已关闭');
                }
                $this->wait(true, $deadline);
                continue;
            }
            $offset += $written;
        }
    }

    private function readReply(float $deadline): mixed
    {
        $line = $this->readLine($deadline);
        if ($line === '') {
            throw new KvClientException('Redis 响应为空');
        }

        $type = $line[0];
        $payload = substr($line, 1);

        return match ($type) {
            '+' => $payload,
            '-' => throw new RuntimeException('Redis 错误：' . $payload),
            ':' => (int) $payload,
            '$' => $this->readBulk((int) $payload, $deadline),
            '*' => $this->readArray((int) $payload, $deadline),
            default => throw new KvClientException('无法解析的 RESP 响应类型：' . $type),
        };
    }

    private function readBulk(int $length, float $deadline): ?string
    {
        if ($length < 0) {
            return null;
        }
        $data = $this->readExact($length, $deadline);
        // 消费 bulk 负载后的 CRLF。
        if ($this->readExact(2, $deadline) !== "\r\n") {
            throw new KvClientException('RESP bulk 响应格式错误');
        }
        return $data;
    }

    /**
     * @return array<int,mixed>|null
     */
    private function readArray(int $count, float $deadline): ?array
    {
        if ($count < 0) {
            return null;
        }
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readReply($deadline);
        }
        return $items;
    }

    private function readLine(float $deadline): string
    {
        while (($pos = strpos($this->buffer, "\r\n")) === false) {
            $this->readChunk($deadline);
        }
        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 2);
        return $line;
    }

    private function readExact(int $length, float $deadline): string
    {
        if ($length === 0) {
            return '';
        }
        while (strlen($this->buffer) < $length) {
            $this->readChunk($deadline);
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data;
    }

    private function readChunk(float $deadline): void
    {
        $this->wait(false, $deadline);

        $chunk = @fread($this->socket, 8192);
        if ($chunk === false) {
            throw new KvClientException('Redis 读取失败');
        }
        if ($chunk === '') {
            if (feof($this->socket)) {
                throw new KvClientException('Redis 连接已关闭');
            }
            if (microtime(true) >= $deadline) {
                throw new KvClientException('Redis 响应超时');
            }
            return;
        }

        $this->buffer .= $chunk;
    }

    private function wait(bool $forWrite, float $deadline): void
    {
        while (true) {
            $read = $forWrite ? null : [$this->socket];
            $write = $forWrite ? [$this->socket] : null;
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                throw new KvClientException('Redis IO 等待失败');
            }
            if ($ready > 0) {
                return;
            }

            if ($this->idleHook !== null) {
                ($this->idleHook)();
            }
            if (microtime(true) >= $deadline) {
                throw new KvClientException('Redis 响应超时');
            }
        }
    }
}
