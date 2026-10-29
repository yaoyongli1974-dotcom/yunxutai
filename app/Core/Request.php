<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = rawurldecode($path);
        $path = '/' . trim(preg_replace('#/+#', '/', $path), '/');
        if ($path === '//') {
            $path = '/';
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;
        return in_array($value, ['1', 1, true, 'on', 'yes'], true);
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function ip(): string
    {
        // 不信任客户端可伪造的 X-Forwarded-For；如部署在可信代理后，请在 Web 服务器层覆写 REMOTE_ADDR。
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }
}
