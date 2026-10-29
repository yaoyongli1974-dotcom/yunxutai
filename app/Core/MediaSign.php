<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 媒体文件短时签名 URL。
 *
 * 签名算法：hash_hmac('sha256', "media:{id}:{exp}", APP_KEY)。
 * 与小程序/客户端约定一致，服务端校验时使用常量时间比较。
 */
final class MediaSign
{
    private const DEFAULT_TTL = 900;

    public static function url(int $mediaId, int $ttl = self::DEFAULT_TTL): string
    {
        $exp = time() + $ttl;
        return "/api/v1/media/{$mediaId}?exp={$exp}&sig=" . self::sign($mediaId, $exp);
    }

    public static function verify(int $mediaId, string $exp, string $sig): bool
    {
        if ($exp === '' || !ctype_digit($exp)) {
            return false;
        }

        $expiresAt = (int) $exp;
        if ($expiresAt <= time()) {
            return false;
        }

        return hash_equals(self::sign($mediaId, $expiresAt), $sig);
    }

    private static function sign(int $mediaId, int $exp): string
    {
        return hash_hmac('sha256', "media:{$mediaId}:{$exp}", (string) config('app.key'));
    }
}
