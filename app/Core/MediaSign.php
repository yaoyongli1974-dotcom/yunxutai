<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 媒体文件短时签名 URL。
 *
 * 签名算法：hash_hmac('sha256', "media:{id}:{exp}:{visibility}", APP_KEY)。
 * 绑定当前可见性：授权下调（如 visitors → members）后旧签名立即失效，
 * 避免撤销授权后仍可通过签发时生成的 URL 访问。
 * 默认有效期 300 秒，缩短签名泄露后的可利用窗口。
 */
final class MediaSign
{
    public const DEFAULT_TTL = 300;

    public static function url(int $mediaId, int $ttl = self::DEFAULT_TTL, string $visibility = ''): string
    {
        $exp = time() + $ttl;
        return "/api/v1/media/{$mediaId}?exp={$exp}&sig=" . self::sign($mediaId, $exp, $visibility);
    }

    public static function verify(int $mediaId, string $exp, string $sig, string $visibility = ''): bool
    {
        if ($exp === '' || !ctype_digit($exp)) {
            return false;
        }

        $expiresAt = (int) $exp;
        if ($expiresAt <= time()) {
            return false;
        }

        return hash_equals(self::sign($mediaId, $expiresAt, $visibility), $sig);
    }

    private static function sign(int $mediaId, int $exp, string $visibility): string
    {
        return hash_hmac('sha256', "media:{$mediaId}:{$exp}:{$visibility}", (string) config('app.key'));
    }
}
