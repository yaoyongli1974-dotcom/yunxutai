<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Memorial
{
    public static function find(int $id): ?array
    {
        return Db::fetch(
            'SELECT m.*, d.full_name, d.gender, d.birth_date, d.death_date, d.birth_place, d.death_place,
                    d.bio, d.cover_media_id
             FROM memorials m
             JOIN deceased d ON d.id = m.deceased_id
             WHERE m.id = ? LIMIT 1',
            [$id]
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return Db::fetch(
            'SELECT m.*, d.full_name, d.gender, d.birth_date, d.death_date, d.birth_place, d.death_place,
                    d.bio, d.cover_media_id
             FROM memorials m
             JOIN deceased d ON d.id = m.deceased_id
             WHERE m.slug = ? LIMIT 1',
            [$slug]
        );
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return (bool) Db::value('SELECT 1 FROM memorials WHERE slug = ? LIMIT 1', [$slug]);
        }
        return (bool) Db::value('SELECT 1 FROM memorials WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $exceptId]);
    }

    public static function create(array $memorial, array $deceased, int $ownerId): int
    {
        return Db::transaction(static function () use ($memorial, $deceased, $ownerId): int {
            $deceasedId = Db::insert('deceased', [
                'full_name' => $deceased['full_name'],
                'gender' => $deceased['gender'] ?? 'unknown',
                'birth_date' => $deceased['birth_date'] ?: null,
                'death_date' => $deceased['death_date'] ?: null,
                'birth_place' => $deceased['birth_place'] ?: null,
                'death_place' => $deceased['death_place'] ?: null,
                'bio' => $deceased['bio'] ?: null,
                'created_by' => $ownerId,
            ]);

            $memorialId = Db::insert('memorials', [
                'deceased_id' => $deceasedId,
                'slug' => $memorial['slug'],
                'title' => $memorial['title'],
                'summary' => $memorial['summary'] ?: null,
                'visibility' => $memorial['visibility'],
                'status' => 'draft',
                'allow_search_index' => 0,
                'allow_guestbook' => 1,
                'moderate_messages' => 1,
                'theme' => 'ink',
                'created_by' => $ownerId,
                'declaration_accepted_at' => date('Y-m-d H:i:s'),
                'declaration_version' => '2026-01',
            ]);

            Db::insert('memorial_members', [
                'memorial_id' => $memorialId,
                'user_id' => $ownerId,
                'role' => 'owner',
                'status' => 'active',
            ]);

            return $memorialId;
        });
    }

    public static function update(int $id, array $data): void
    {
        Db::update('memorials', $data, 'id = ?', [$id]);
    }

    public static function updateDeceased(int $deceasedId, array $data): void
    {
        Db::update('deceased', $data, 'id = ?', [$deceasedId]);
    }

    public static function forUser(int $userId): array
    {
        return Db::fetchAll(
            'SELECT m.*, d.full_name, d.birth_date, d.death_date, mm.role AS member_role
             FROM memorial_members mm
             JOIN memorials m ON m.id = mm.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE mm.user_id = ? AND mm.status = ?
             ORDER BY m.updated_at DESC',
            [$userId, 'active']
        );
    }

    public static function publicCount(string $query = ''): int
    {
        $params = [];
        $where = "m.status = 'active' AND m.visibility = 'public' AND m.verification_status = 'verified'";
        if ($query !== '') {
            $where .= ' AND (d.full_name LIKE ? OR m.title LIKE ? OR m.summary LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }
        return (int) Db::value(
            "SELECT COUNT(*) FROM memorials m JOIN deceased d ON d.id = m.deceased_id WHERE {$where}",
            $params
        );
    }

    public static function publicPaginate(string $query, int $limit, int $offset): array
    {
        $params = [];
        $where = "m.status = 'active' AND m.visibility = 'public' AND m.verification_status = 'verified'";
        if ($query !== '') {
            $where .= ' AND (d.full_name LIKE ? OR m.title LIKE ? OR m.summary LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }
        return Db::fetchAll(
            "SELECT m.*, d.full_name, d.birth_date, d.death_date, d.birth_place, d.cover_media_id
             FROM memorials m JOIN deceased d ON d.id = m.deceased_id
             WHERE {$where}
             ORDER BY COALESCE(m.published_at, m.created_at) DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function recentPublic(int $limit = 4): array
    {
        return Db::fetchAll(
            "SELECT m.*, d.full_name, d.birth_date, d.death_date, d.cover_media_id
             FROM memorials m JOIN deceased d ON d.id = m.deceased_id
             WHERE m.status = 'active' AND m.visibility = 'public' AND m.verification_status = 'verified'
             ORDER BY COALESCE(m.published_at, m.created_at) DESC
             LIMIT {$limit}"
        );
    }

    public static function indexable(): array
    {
        return Db::fetchAll(
            "SELECT slug, updated_at FROM memorials
             WHERE status = 'active' AND visibility = 'public' AND allow_search_index = 1
               AND verification_status = 'verified'
             ORDER BY updated_at DESC LIMIT 5000"
        );
    }

    public static function adminCount(array $filters = []): int
    {
        [$where, $params] = self::adminWhere($filters);
        return (int) Db::value(
            "SELECT COUNT(*) FROM memorials m JOIN deceased d ON d.id = m.deceased_id WHERE {$where}",
            $params
        );
    }

    public static function adminPaginate(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::adminWhere($filters);
        return Db::fetchAll(
            "SELECT m.*, d.full_name, d.birth_date, d.death_date, u.display_name AS creator_name
             FROM memorials m
             JOIN deceased d ON d.id = m.deceased_id
             LEFT JOIN users u ON u.id = m.created_by
             WHERE {$where}
             ORDER BY m.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    private static function adminWhere(array $filters): array
    {
        $where = '1 = 1';
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND (d.full_name LIKE ? OR m.title LIKE ? OR m.slug LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params = [$like, $like, $like];
        }
        if (($filters['status'] ?? '') !== '') {
            $where .= ' AND m.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['visibility'] ?? '') !== '') {
            $where .= ' AND m.visibility = ?';
            $params[] = $filters['visibility'];
        }
        return [$where, $params];
    }

    public static function statusCounts(): array
    {
        $rows = Db::fetchAll('SELECT status, COUNT(*) AS total FROM memorials GROUP BY status');
        $counts = ['draft' => 0, 'active' => 0, 'suspended' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    public static function counts(int $memorialId): array
    {
        return [
            'tributes' => (int) Db::value('SELECT COUNT(*) FROM tributes WHERE memorial_id = ? AND status = ?', [$memorialId, 'visible']),
            'messages' => (int) Db::value('SELECT COUNT(*) FROM messages WHERE memorial_id = ? AND status = ?', [$memorialId, 'visible']),
            'photos' => (int) Db::value("SELECT COUNT(*) FROM media WHERE memorial_id = ? AND status = 'active' AND kind = 'photo'", [$memorialId]),
            'articles' => (int) Db::value("SELECT COUNT(*) FROM articles WHERE memorial_id = ? AND status = 'published'", [$memorialId]),
            'members' => (int) Db::value('SELECT COUNT(*) FROM memorial_members WHERE memorial_id = ? AND status = ?', [$memorialId, 'active']),
        ];
    }
}
