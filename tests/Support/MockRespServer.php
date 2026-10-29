<?php
declare(strict_types=1);

/**
 * 进程内 mock RESP server（仅测试使用）。
 *
 * 使用真实 TCP 临时端口，但服务端与客户端在同一 PHP 进程内：
 * RespClient 读取阻塞时通过 idleHook 调用 pump()，以非阻塞 socket 完成
 * 「收请求 -> 解析 RESP -> 生成响应 -> 写回」的一问一答，无需 redis-server。
 */
final class MockRespServer
{
    /** @var resource */
    private $server;

    /** @var resource|null */
    private $client = null;

    private string $buffer = '';
    private string $out = '';

    /** @var array<string,string> */
    private array $data = [];

    /** @var array<string,int> */
    private array $expires = [];

    /** @var array<int,array<int,string>> */
    private array $commands = [];

    private string $url;

    public function __construct(string $host = '127.0.0.1', int $port = 0)
    {
        $server = @stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);
        if (!is_resource($server)) {
            throw new RuntimeException('无法启动 mock RESP server：' . $errstr);
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        $name = (string) stream_socket_get_name($server, false);
        if ($name === '') {
            throw new RuntimeException('无法获取 mock RESP server 端口');
        }
        $this->url = 'redis://' . $name . '/0';
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array<int,array<int,string>>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    /**
     * 推进一次服务端事件循环：接受连接、读请求、执行并写回。
     */
    public function pump(): void
    {
        if ($this->client === null) {
            $accepted = @stream_socket_accept($this->server, 0);
            if ($accepted === false) {
                return;
            }
            stream_set_blocking($accepted, false);
            $this->client = $accepted;
            $this->buffer = '';
            $this->out = '';
        }

        while (true) {
            $chunk = @fread($this->client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->buffer .= $chunk;
        }

        if (feof($this->client) && $this->buffer === '') {
            $this->dropClient();
            return;
        }

        while (($request = $this->parseRequest()) !== null) {
            $this->commands[] = $request;
            $this->out .= $this->execute($request);
        }

        while ($this->out !== '' && $this->client !== null) {
            $written = @fwrite($this->client, $this->out);
            if ($written === false || $written === 0) {
                break;
            }
            $this->out = substr($this->out, $written);
        }
    }

    public function close(): void
    {
        $this->dropClient();
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
    }

    private function dropClient(): void
    {
        if (is_resource($this->client)) {
            @fclose($this->client);
        }
        $this->client = null;
        $this->buffer = '';
        $this->out = '';
    }

    /**
     * @return array<int,string>|null
     */
    private function parseRequest(): ?array
    {
        if ($this->buffer === '' || $this->buffer[0] !== '*') {
            return null;
        }

        $eol = strpos($this->buffer, "\r\n");
        if ($eol === false) {
            return null;
        }

        $count = (int) substr($this->buffer, 1, $eol - 1);
        $offset = $eol + 2;
        $args = [];

        for ($i = 0; $i < $count; $i++) {
            if (!isset($this->buffer[$offset]) || $this->buffer[$offset] !== '$') {
                return null;
            }
            $eol = strpos($this->buffer, "\r\n", $offset);
            if ($eol === false) {
                return null;
            }
            $length = (int) substr($this->buffer, $offset + 1, $eol - $offset - 1);
            $start = $eol + 2;
            if (strlen($this->buffer) < $start + $length + 2) {
                return null;
            }
            if (substr($this->buffer, $start + $length, 2) !== "\r\n") {
                return null;
            }
            $args[] = substr($this->buffer, $start, $length);
            $offset = $start + $length + 2;
        }

        $this->buffer = substr($this->buffer, $offset);
        return $args;
    }

    /**
     * @param array<int,string> $args
     */
    private function execute(array $args): string
    {
        $command = strtoupper($args[0] ?? '');

        return match ($command) {
            'PING' => "+PONG\r\n",
            'AUTH', 'SELECT' => "+OK\r\n",
            'INCR' => $this->incr((string) ($args[1] ?? '')),
            'EXPIRE' => $this->expire($args),
            'GET' => $this->get((string) ($args[1] ?? '')),
            'SET' => $this->set($args),
            'SETEX' => $this->setex($args),
            'DEL' => $this->del($args),
            'TTL' => $this->ttl((string) ($args[1] ?? '')),
            default => "-ERR unknown command '{$command}'\r\n",
        };
    }

    private function incr(string $key): string
    {
        $this->expireIfNeeded($key);
        $value = isset($this->data[$key]) ? (int) $this->data[$key] + 1 : 1;
        $this->data[$key] = (string) $value;
        return ':' . $value . "\r\n";
    }

    /**
     * @param array<int,string> $args
     */
    private function expire(array $args): string
    {
        $key = (string) ($args[1] ?? '');
        $this->expireIfNeeded($key);
        if (!isset($this->data[$key])) {
            return ":0\r\n";
        }

        $nx = isset($args[3]) && strtoupper($args[3]) === 'NX';
        if ($nx && isset($this->expires[$key])) {
            return ":0\r\n";
        }

        $this->expires[$key] = time() + (int) ($args[2] ?? 0);
        return ":1\r\n";
    }

    private function get(string $key): string
    {
        $this->expireIfNeeded($key);
        if (!isset($this->data[$key])) {
            return "$-1\r\n";
        }
        return $this->bulk($this->data[$key]);
    }

    /**
     * @param array<int,string> $args
     */
    private function set(array $args): string
    {
        $key = (string) ($args[1] ?? '');
        $value = (string) ($args[2] ?? '');
        $this->data[$key] = $value;
        unset($this->expires[$key]);

        $option = strtoupper((string) ($args[3] ?? ''));
        if ($option === 'EX' && isset($args[4])) {
            $this->expires[$key] = time() + (int) $args[4];
        }
        return "+OK\r\n";
    }

    /**
     * @param array<int,string> $args
     */
    private function setex(array $args): string
    {
        $key = (string) ($args[1] ?? '');
        $this->data[$key] = (string) ($args[3] ?? '');
        $this->expires[$key] = time() + (int) ($args[2] ?? 0);
        return "+OK\r\n";
    }

    /**
     * @param array<int,string> $args
     */
    private function del(array $args): string
    {
        $removed = 0;
        foreach (array_slice($args, 1) as $key) {
            $this->expireIfNeeded($key);
            if (isset($this->data[$key])) {
                unset($this->data[$key], $this->expires[$key]);
                $removed++;
            }
        }
        return ':' . $removed . "\r\n";
    }

    private function ttl(string $key): string
    {
        $this->expireIfNeeded($key);
        if (!isset($this->data[$key])) {
            return ":-2\r\n";
        }
        if (!isset($this->expires[$key])) {
            return ":-1\r\n";
        }
        return ':' . max(0, $this->expires[$key] - time()) . "\r\n";
    }

    private function expireIfNeeded(string $key): void
    {
        if (isset($this->expires[$key]) && $this->expires[$key] <= time()) {
            unset($this->data[$key], $this->expires[$key]);
        }
    }

    private function bulk(string $value): string
    {
        return '$' . strlen($value) . "\r\n" . $value . "\r\n";
    }
}
