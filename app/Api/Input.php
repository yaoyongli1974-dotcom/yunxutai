<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Request;

/**
 * 请求体读取：兼容 form-urlencoded 与 application/json。
 * 小程序端固定发送 JSON，而 App\Core\Request 仅解析 $_POST，故在此补一层。
 */
final class Input
{
    /** @var array<int, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function all(Request $request): array
    {
        $id = spl_object_id($request);
        if (array_key_exists($id, self::$cache)) {
            return self::$cache[$id];
        }

        $data = $request->post;
        $contentType = strtolower((string) ($request->server['CONTENT_TYPE'] ?? $request->server['HTTP_CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        }

        return self::$cache[$id] = $data;
    }

    public static function get(Request $request, string $key, mixed $default = null): mixed
    {
        return self::all($request)[$key] ?? $default;
    }

    public static function string(Request $request, string $key, string $default = ''): string
    {
        $value = self::get($request, $key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function bool(Request $request, string $key): bool
    {
        $value = self::get($request, $key, false);
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    public static function int(Request $request, string $key, int $default = 0): int
    {
        $value = self::get($request, $key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }
}
