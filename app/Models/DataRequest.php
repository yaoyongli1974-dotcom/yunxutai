<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class DataRequest
{
    public static function create(int $userId, ?int $memorialId, string $type, ?string $note): int
    {
        return Db::insert('data_requests', [
            'user_id' => $userId,
            'memorial_id' => $memorialId,
            'type' => $type,
            'note' => $note ?: null,
        ]);
    }

    public static function forUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT * FROM data_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
            [$userId]
        );
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND dr.status = ?';
            $params[] = $status;
        }
        return Db::fetchAll(
            "SELECT dr.*, u.display_name, u.email
             FROM data_requests dr JOIN users u ON u.id = dr.user_id
             WHERE {$where} ORDER BY dr.created_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(?string $status = null): int
    {
        if ($status === null) {
            return (int) Db::value('SELECT COUNT(*) FROM data_requests');
        }
        return (int) Db::value('SELECT COUNT(*) FROM data_requests WHERE status = ?', [$status]);
    }

    public static function updateStatus(int $id, string $status, int $handlerId, ?string $resolution): void
    {
        Db::update('data_requests', [
            'status' => $status,
            'handled_by' => $handlerId,
            'handled_at' => date('Y-m-d H:i:s'),
            'resolution' => $resolution ?: null,
        ], 'id = ?', [$id]);
    }
}
