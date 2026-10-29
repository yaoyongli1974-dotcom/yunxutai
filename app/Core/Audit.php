<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): void
    {
        try {
            $actorId = isset($_SESSION['uid']) && is_numeric($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
            Db::insert('audit_logs', [
                'actor_user_id' => $actorId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_hash' => Str::ipHash($_SERVER['REMOTE_ADDR'] ?? null),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不应中断用户操作，但必须留在错误日志中。
            error_log('audit failed: ' . $e->getMessage());
        }
    }
}
