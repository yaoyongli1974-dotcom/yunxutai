<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 统一限流键：场景 + 哈希后的身份/资源，绝不把明文 IP、Token、密码、邮箱写入 throttle。
 */
final class RateLimitKeys
{
    public static function make(string $scene, ?string $identity = null, ?string $resource = null): string
    {
        $who = $identity === null || $identity === ''
            ? 'anon:' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))
            : hash('sha256', $identity);
        $key = preg_replace('/[^a-z0-9_.:-]/i', '', $scene) . ':' . $who;
        if ($resource !== null && $resource !== '') {
            $key .= ':' . hash('sha256', $resource);
        }
        return $key;
    }
}
