<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Article
{
    public static function forMemorial(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT * FROM articles WHERE memorial_id = ? ORDER BY COALESCE(published_at, created_at) DESC',
            [$memorialId]
        );
    }

    public static function publishedFor(int $memorialId): array
    {
        return Db::fetchAll(
            "SELECT * FROM articles WHERE memorial_id = ? AND status = 'published'
             ORDER BY COALESCE(published_at, created_at) DESC",
            [$memorialId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM articles WHERE id = ? LIMIT 1', [$id]);
    }

    public static function create(int $memorialId, array $data, int $userId): int
    {
        return Db::insert('articles', [
            'memorial_id' => $memorialId,
            'title' => $data['title'],
            'content' => $data['content'],
            'author_name' => $data['author_name'] ?: null,
            'status' => $data['status'],
            'created_by' => $userId,
            'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public static function update(int $id, array $data): void
    {
        $current = self::find($id);
        $publishedAt = $current['published_at'] ?? null;
        if ($data['status'] === 'published' && !$publishedAt) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        Db::update('articles', [
            'title' => $data['title'],
            'content' => $data['content'],
            'author_name' => $data['author_name'] ?: null,
            'status' => $data['status'],
            'published_at' => $publishedAt,
        ], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::query('DELETE FROM articles WHERE id = ?', [$id]);
    }

    public static function pending(int $limit, int $offset): array
    {
        return Db::fetchAll(
            "SELECT a.*, m.slug, d.full_name
             FROM articles a
             JOIN memorials m ON m.id = a.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE a.status = 'pending'
             ORDER BY a.created_at ASC LIMIT {$limit} OFFSET {$offset}"
        );
    }

    public static function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM articles WHERE status = 'pending'");
    }

    public static function setStatus(int $id, string $status, ?string $note = null): void
    {
        $data = ['status' => $status, 'review_note' => $note];
        if ($status === 'published') {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        Db::update('articles', $data, 'id = ?', [$id]);
    }
}
