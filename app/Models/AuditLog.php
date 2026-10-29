<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class AuditLog
{
    public static function paginate(string $query, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($query !== '') {
            $where .= ' AND (a.action LIKE ? OR a.target_type LIKE ? OR u.display_name LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }
        return Db::fetchAll(
            "SELECT a.*, u.display_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE {$where}
             ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function count(string $query = ''): int
    {
        if ($query === '') {
            return (int) Db::value('SELECT COUNT(*) FROM audit_logs');
        }
        $like = '%' . $query . '%';
        return (int) Db::value(
            'SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.action LIKE ? OR a.target_type LIKE ? OR u.display_name LIKE ?',
            [$like, $like, $like]
        );
    }
}
