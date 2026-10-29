<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class LifeEvent
{
    public static function forMemorial(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT * FROM life_events WHERE memorial_id = ?
             ORDER BY sort_order ASC, event_date IS NULL, event_date ASC, id ASC',
            [$memorialId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM life_events WHERE id = ? LIMIT 1', [$id]);
    }

    public static function create(int $memorialId, array $data, int $userId): int
    {
        return Db::insert('life_events', [
            'memorial_id' => $memorialId,
            'event_date' => $data['event_date'] ?: null,
            'year_label' => $data['year_label'] ?: null,
            'title' => $data['title'],
            'description' => $data['description'] ?: null,
            'location' => $data['location'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'created_by' => $userId,
        ]);
    }

    public static function update(int $id, array $data): void
    {
        Db::update('life_events', [
            'event_date' => $data['event_date'] ?: null,
            'year_label' => $data['year_label'] ?: null,
            'title' => $data['title'],
            'description' => $data['description'] ?: null,
            'location' => $data['location'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        Db::query('DELETE FROM life_events WHERE id = ?', [$id]);
    }
}
