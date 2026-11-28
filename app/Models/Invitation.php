<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Core\Str;

final class Invitation
{
    public static function create(int $memorialId, ?string $email, string $role, int $createdBy, int $days = 7): array
    {
        $token = Str::random(32);
        $id = Db::insert('invitations', [
            'memorial_id' => $memorialId,
            'email' => $email ?: null,
            'role' => $role,
            'token' => $token,
            // 明文仅供存量视图渲染邀请链接；校验一律使用散列值。
            'token_hash' => hash('sha256', $token),
            'created_by' => $createdBy,
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        ]);
        return ['id' => $id, 'token' => $token];
    }

    public static function findOpenByToken(string $token): ?array
    {
        $select = "SELECT i.*, m.slug, m.title, m.created_by, d.full_name
             FROM invitations i
             JOIN memorials m ON m.id = i.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE %s AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > NOW()
             LIMIT 1";

        $invitation = Db::fetch(sprintf($select, 'i.token_hash = ?'), [hash('sha256', $token)]);
        if ($invitation !== null) {
            return $invitation;
        }
        // 兼容迁移前只存明文 token 的存量邀请；新邀请的 token_hash 已写入，不再按明文命中。
        return Db::fetch(sprintf($select, 'i.token_hash IS NULL AND i.token = ?'), [$token]);
    }

    public static function pendingFor(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT i.*, u.display_name AS creator_name
             FROM invitations i LEFT JOIN users u ON u.id = i.created_by
             WHERE i.memorial_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > NOW()
             ORDER BY i.created_at DESC',
            [$memorialId]
        );
    }

    public static function accept(int $id, int $userId): void
    {
        Db::update('invitations', [
            'accepted_by' => $userId,
            'accepted_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    public static function revoke(int $id, int $memorialId): void
    {
        Db::update(
            'invitations',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'id = ? AND memorial_id = ?',
            [$id, $memorialId]
        );
    }
}
