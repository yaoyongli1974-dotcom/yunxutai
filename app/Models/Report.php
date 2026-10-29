<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Report
{
    public static function create(array $data): int
    {
        $reason = (string) $data['reason'];
        return Db::insert('reports', [
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'memorial_id' => $data['memorial_id'] ?? null,
            'reporter_user_id' => $data['reporter_user_id'] ?? null,
            'reporter_name' => $data['reporter_name'] ?? null,
            'contact' => $data['contact'] ?? null,
            'reason' => $reason,
            'priority' => self::priorityForReason($reason),
            'detail' => $data['detail'] ?? null,
        ]);
    }

    public static function priorityForReason(string $reason): string
    {
        return match ($reason) {
            'living_person', 'fake_person' => 'urgent',
            'privacy', 'insult', 'illegal' => 'high',
            default => 'normal',
        };
    }

    public static function find(int $id): ?array
    {
        return Db::fetch(
            'SELECT r.*, m.slug, d.full_name AS memorial_name
             FROM reports r
             LEFT JOIN memorials m ON m.id = r.memorial_id
             LEFT JOIN deceased d ON d.id = m.deceased_id
             WHERE r.id = ? LIMIT 1',
            [$id]
        );
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        return Db::fetchAll(
            "SELECT r.*, m.slug, d.full_name AS memorial_name, u.display_name AS reporter_display
             FROM reports r
             LEFT JOIN memorials m ON m.id = r.memorial_id
             LEFT JOIN deceased d ON d.id = m.deceased_id
             LEFT JOIN users u ON u.id = r.reporter_user_id
             WHERE {$where}
             ORDER BY FIELD(r.status, 'open', 'reviewing', 'resolved', 'dismissed'),
                      FIELD(r.priority, 'urgent', 'high', 'normal'),
                      r.created_at ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(?string $status = null): int
    {
        if ($status === null) {
            return (int) Db::value('SELECT COUNT(*) FROM reports');
        }
        return (int) Db::value('SELECT COUNT(*) FROM reports WHERE status = ?', [$status]);
    }

    public static function updateStatus(int $id, string $status, int $handlerId, ?string $resolution): void
    {
        Db::update('reports', [
            'status' => $status,
            'handled_by' => $handlerId,
            'handled_at' => date('Y-m-d H:i:s'),
            'resolution' => $resolution ?: null,
        ], 'id = ?', [$id]);
    }
}
