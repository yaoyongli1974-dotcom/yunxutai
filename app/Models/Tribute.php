<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Tribute
{
    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM tributes WHERE id = ? LIMIT 1', [$id]);
    }

    public static function create(
        int $memorialId,
        ?int $userId,
        string $type,
        ?string $note,
        bool $anonymous,
        ?string $ipHash,
        string $status = 'visible'
    ): int {
        return Db::insert('tributes', [
            'memorial_id' => $memorialId,
            'user_id' => $userId,
            'type' => $type,
            'note' => $note ?: null,
            'is_anonymous' => $anonymous ? 1 : 0,
            'ip_hash' => $ipHash,
            'status' => $status,
        ]);
    }

    public static function recentVisible(int $memorialId, int $limit = 12): array
    {
        return Db::fetchAll(
            "SELECT t.*, u.display_name
             FROM tributes t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.memorial_id = ? AND t.status = 'visible'
             ORDER BY t.created_at DESC, t.id DESC LIMIT {$limit}",
            [$memorialId]
        );
    }

    public static function visiblePaginate(int $memorialId, int $limit, int $offset): array
    {
        return Db::fetchAll(
            "SELECT t.*, u.display_name
             FROM tributes t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.memorial_id = ? AND t.status = 'visible'
             ORDER BY t.created_at DESC, t.id DESC LIMIT {$limit} OFFSET {$offset}",
            [$memorialId]
        );
    }

    public static function visibleCount(int $memorialId): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM tributes WHERE memorial_id = ? AND status = ?',
            [$memorialId, 'visible']
        );
    }

    public static function countsByType(int $memorialId): array
    {
        $rows = Db::fetchAll(
            'SELECT type, COUNT(*) AS total FROM tributes WHERE memorial_id = ? AND status = ? GROUP BY type',
            [$memorialId, 'visible']
        );
        $counts = ['flower' => 0, 'candle' => 0, 'incense' => 0, 'bow' => 0];
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['total'];
        }
        return $counts;
    }

    public static function forModeration(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT t.*, u.display_name FROM tributes t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.memorial_id = ? ORDER BY t.created_at DESC LIMIT 100',
            [$memorialId]
        );
    }

    public static function setStatus(int $id, string $status, int $auditorId): void
    {
        Db::update('tributes', [
            'status' => $status,
            'audited_by' => $auditorId,
            'audited_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    /** @return array<int, array{type:string,total:int}> */
    public static function recentTypesForUser(int $userId, int $days = 30): array
    {
        return Db::fetchAll(
            "SELECT type, COUNT(*) AS total FROM tributes
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY type",
            [$userId]
        );
    }
}
