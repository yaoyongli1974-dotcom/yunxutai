<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function random(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function slugify(string $value): string
    {
        $value = trim($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower((string) ($ascii !== false ? $ascii : $value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 60);
        return $slug !== '' ? $slug : 'memory-' . self::random(4);
    }

    public static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])$/', $slug);
    }

    public static function ipHash(?string $ip): string
    {
        return hash_hmac('sha256', (string) ($ip ?? ''), (string) config('app.key'));
    }

    public static function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '访客';
        }
        $length = mb_strlen($name);
        if ($length <= 1) {
            return $name;
        }
        return mb_substr($name, 0, 1) . str_repeat('*', min(2, $length - 1));
    }
}
