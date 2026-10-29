<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class ServiceRequest
{
    public static function create(?int $userId, ?int $memorialId, string $name, string $contact, string $type, ?string $message): int
    {
        return Db::insert('service_requests', [
            'user_id' => $userId,
            'memorial_id' => $memorialId,
            'name' => $name,
            'contact' => $contact,
            'service_type' => $type,
            'message' => $message ?: null,
        ]);
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        return Db::fetchAll(
            "SELECT * FROM service_requests WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(?string $status = null): int
    {
        if ($status === null) {
            return (int) Db::value('SELECT COUNT(*) FROM service_requests');
        }
        return (int) Db::value('SELECT COUNT(*) FROM service_requests WHERE status = ?', [$status]);
    }

    public static function setStatus(int $id, string $status, int $handlerId): void
    {
        Db::update('service_requests', [
            'status' => $status,
            'handled_by' => $handlerId,
            'handled_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }
}
