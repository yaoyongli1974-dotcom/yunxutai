<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Member
{
    public static function all(int $memorialId): array
    {
        return Db::fetchAll(
            "SELECT mm.*, u.display_name, u.email, u.last_login_at
             FROM memorial_members mm
             JOIN users u ON u.id = mm.user_id
             WHERE mm.memorial_id = ? AND mm.status <> 'revoked'
             ORDER BY FIELD(mm.role, 'owner', 'editor', 'viewer'), mm.created_at",
            [$memorialId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM memorial_members WHERE id = ? LIMIT 1', [$id]);
    }

    public static function roleOf(int $memorialId, int $userId): ?string
    {
        $role = Db::value(
            'SELECT role FROM memorial_members WHERE memorial_id = ? AND user_id = ? AND status = ? LIMIT 1',
            [$memorialId, $userId, 'active']
        );
        return $role !== null ? (string) $role : null;
    }

    public static function upsert(int $memorialId, int $userId, string $role, ?int $invitedBy = null): void
    {
        $existing = Db::fetch(
            'SELECT id, status FROM memorial_members WHERE memorial_id = ? AND user_id = ? LIMIT 1',
            [$memorialId, $userId]
        );
        if ($existing) {
            Db::update(
                'memorial_members',
                ['role' => $role, 'status' => 'active', 'invited_by' => $invitedBy],
                'id = ?',
                [(int) $existing['id']]
            );
        } else {
            Db::insert('memorial_members', [
                'memorial_id' => $memorialId,
                'user_id' => $userId,
                'role' => $role,
                'status' => 'active',
                'invited_by' => $invitedBy,
            ]);
        }
    }

    public static function revoke(int $id): void
    {
        Db::update('memorial_members', ['status' => 'revoked'], 'id = ?', [$id]);
    }

    public static function updateRole(int $id, string $role): void
    {
        Db::update('memorial_members', ['role' => $role], 'id = ?', [$id]);
    }
}
