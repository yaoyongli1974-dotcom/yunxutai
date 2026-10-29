<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Claim
{
    public static function create(int $memorialId, int $userId, string $relationship, string $statement, ?string $evidenceNote): int
    {
        return Db::insert('claims', [
            'memorial_id' => $memorialId,
            'user_id' => $userId,
            'relationship' => $relationship,
            'statement' => $statement,
            'evidence_note' => $evidenceNote ?: null,
        ]);
    }

    public static function pendingForUser(int $memorialId, int $userId): bool
    {
        return (bool) Db::value(
            "SELECT 1 FROM claims WHERE memorial_id = ? AND user_id = ? AND status = 'open' LIMIT 1",
            [$memorialId, $userId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::fetch(
            'SELECT c.*, m.slug, d.full_name AS memorial_name, u.display_name, u.email
             FROM claims c
             JOIN memorials m ON m.id = c.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             JOIN users u ON u.id = c.user_id
             WHERE c.id = ? LIMIT 1',
            [$id]
        );
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND c.status = ?';
            $params[] = $status;
        }
        return Db::fetchAll(
            "SELECT c.*, m.slug, d.full_name AS memorial_name, u.display_name, u.email
             FROM claims c
             JOIN memorials m ON m.id = c.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             JOIN users u ON u.id = c.user_id
             WHERE {$where}
             ORDER BY FIELD(c.status, 'open', 'approved', 'rejected'), c.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(?string $status = null): int
    {
        if ($status === null) {
            return (int) Db::value('SELECT COUNT(*) FROM claims');
        }
        return (int) Db::value('SELECT COUNT(*) FROM claims WHERE status = ?', [$status]);
    }

    public static function updateStatus(int $id, string $status, int $handlerId, ?string $resolution): void
    {
        Db::update('claims', [
            'status' => $status,
            'handled_by' => $handlerId,
            'handled_at' => date('Y-m-d H:i:s'),
            'resolution' => $resolution ?: null,
        ], 'id = ?', [$id]);
    }
}
