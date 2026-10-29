<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;
use App\Core\Storage;

final class Media
{
    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM media WHERE id = ? LIMIT 1', [$id]);
    }

    public static function forMemorial(int $memorialId): array
    {
        return Db::fetchAll(
            'SELECT * FROM media WHERE memorial_id = ?
             ORDER BY sort_order ASC, id DESC',
            [$memorialId]
        );
    }

    public static function visible(int $memorialId, bool $includeMembersOnly): array
    {
        $visibility = $includeMembersOnly ? "('visitors','members')" : "('visitors')";
        return Db::fetchAll(
            "SELECT * FROM media WHERE memorial_id = ? AND status = 'active' AND visibility IN {$visibility}
             ORDER BY sort_order ASC, id DESC",
            [$memorialId]
        );
    }

    public static function photos(int $memorialId, int $limit = 6, bool $includeMembersOnly = false): array
    {
        $visibility = $includeMembersOnly ? "('visitors','members')" : "('visitors')";
        return Db::fetchAll(
            "SELECT * FROM media
             WHERE memorial_id = ? AND status = 'active' AND kind = 'photo' AND visibility IN {$visibility}
             ORDER BY sort_order ASC, id DESC LIMIT {$limit}",
            [$memorialId]
        );
    }

    public static function create(int $memorialId, int $uploaderId, array $file, string $kind, string $visibility, ?string $caption = null): int
    {
        return Db::insert('media', [
            'memorial_id' => $memorialId,
            'uploader_id' => $uploaderId,
            'kind' => $kind,
            'disk_path' => $file['disk_path'],
            'original_name' => $file['original_name'],
            'mime' => $file['mime'],
            'size_bytes' => $file['size_bytes'],
            'caption' => $caption ?: null,
            'visibility' => $visibility,
        ]);
    }

    public static function hide(int $id): void
    {
        Db::update('media', ['status' => 'hidden'], 'id = ?', [$id]);
    }

    public static function show(int $id): void
    {
        Db::update('media', ['status' => 'active'], 'id = ?', [$id]);
    }

    public static function setVisibility(int $id, string $visibility): void
    {
        if (!in_array($visibility, ['visitors', 'members'], true)) {
            return;
        }
        Db::update('media', ['visibility' => $visibility], 'id = ?', [$id]);
    }

    public static function delete(int $id): void
    {
        $media = self::find($id);
        if ($media) {
            Storage::delete($media['disk_path']);
            Db::query('DELETE FROM media WHERE id = ?', [$id]);
        }
    }

    public static function setCover(int $memorialId, int $mediaId): void
    {
        $memorial = Memorial::find($memorialId);
        if ($memorial) {
            Memorial::updateDeceased((int) $memorial['deceased_id'], ['cover_media_id' => $mediaId]);
        }
    }

    public static function countFor(int $memorialId): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM media WHERE memorial_id = ? AND status = 'active'",
            [$memorialId]
        );
    }
}
