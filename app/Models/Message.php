<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Message
{
    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM messages WHERE id = ? LIMIT 1', [$id]);
    }

    public static function create(int $memorialId, ?int $userId, string $authorName, string $content, ?string $ipHash, string $status): int
    {
        return Db::insert('messages', [
            'memorial_id' => $memorialId,
            'user_id' => $userId,
            'author_name' => $authorName,
            'content' => $content,
            'status' => $status,
            'ip_hash' => $ipHash,
        ]);
    }

    public static function visiblePaginate(int $memorialId, int $limit, int $offset): array
    {
        return Db::fetchAll(
            "SELECT * FROM messages WHERE memorial_id = ? AND status = 'visible'
             ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            [$memorialId]
        );
    }

    public static function visibleCount(int $memorialId): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM messages WHERE memorial_id = ? AND status = ?',
            [$memorialId, 'visible']
        );
    }

    public static function recentVisible(int $memorialId, int $limit = 5): array
    {
        return Db::fetchAll(
            "SELECT * FROM messages WHERE memorial_id = ? AND status = 'visible'
             ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            [$memorialId]
        );
    }

    public static function forModeration(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT * FROM messages WHERE memorial_id = ? ORDER BY created_at DESC LIMIT 100',
            [$memorialId]
        );
    }

    public static function pendingAll(int $limit = 50): array
    {
        return Db::fetchAll(
            "SELECT msg.*, m.slug, d.full_name
             FROM messages msg
             JOIN memorials m ON m.id = msg.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE msg.status = 'pending'
             ORDER BY msg.created_at ASC LIMIT {$limit}"
        );
    }

    public static function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM messages WHERE status = 'pending'");
    }

    public static function setStatus(int $id, string $status, int $auditorId): void
    {
        Db::update('messages', [
            'status' => $status,
            'audited_by' => $auditorId,
            'audited_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }
}
