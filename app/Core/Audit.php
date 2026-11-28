<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    /** 这些键的值永不进入审计日志（键名大小写不敏感）。 */
    private const REDACTED_KEYS = ['password', 'token', 'api_key', 'secret', 'authorization'];

    private const MAX_STRING_LENGTH = 200;
    private const MAX_META_KEYS = 20;
    private const MAX_DEPTH = 4;

    public static function log(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        ?int $actorId = null
    ): void
    {
        try {
            $actorId ??= isset($_SESSION['uid']) && is_numeric($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
            Db::insert('audit_logs', [
                'actor_user_id' => $actorId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'meta' => $meta === [] ? null : json_encode(self::sanitizeMeta($meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_hash' => Str::ipHash($_SERVER['REMOTE_ADDR'] ?? null),
                'request_id' => $GLOBALS['request_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // 审计失败不应中断用户操作，但必须留在错误日志中。
            error_log('audit failed [request_id=' . ($GLOBALS['request_id'] ?? '-') . ']: ' . $e->getMessage());
        }
    }

    /**
     * 审计元数据脱敏：邮箱保留域名、身份证号保留后四位、凭据键整体遮蔽，
     * 长文本与深层/超大数组只留截断摘要。
     *
     * @param array<mixed,mixed> $meta
     * @return array<mixed,mixed>
     */
    private static function sanitizeMeta(array $meta, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['_truncated' => true];
        }
        $clean = [];
        $count = 0;
        foreach ($meta as $key => $value) {
            if ($count++ >= self::MAX_META_KEYS) {
                $clean['_truncated'] = true;
                break;
            }
            $clean[$key] = self::sanitizeValue((string) $key, $value, $depth);
        }
        return $clean;
    }

    private static function sanitizeValue(string $key, mixed $value, int $depth): mixed
    {
        if (in_array(strtolower($key), self::REDACTED_KEYS, true)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            return self::sanitizeMeta($value, $depth + 1);
        }
        if ($value instanceof \Throwable) {
            return self::sanitizeString($value->getMessage());
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        if (is_object($value) || is_resource($value)) {
            return '[redacted]';
        }
        return $value;
    }

    /** 折叠换行、遮蔽邮箱/身份证号，并对过长的原始文本只保留截断摘要。 */
    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            static fn (array $m): string => self::maskEmail($m[0]),
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '/(?<!\d)\d{17}[\dXx](?!\d)/',
            static fn (array $m): string => str_repeat('*', 14) . substr($m[0], -4),
            $value
        ) ?? $value;
        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH) . '…(truncated)';
        }
        return $value;
    }

    /** 邮箱脱敏：只保留本地部分首字母与原域名，便于排障又不泄露账号。 */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '[redacted]';
        }
        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
