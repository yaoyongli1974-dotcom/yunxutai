<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;

/** 数字展馆：草稿→发布→下线；发布时必须复核每个内容的可见性。 */
final class ExhibitionService
{
    public const ITEM_TYPES = ['archive', 'person', 'event', 'life_event'];

    public static function create(?array $user, int $familyId, array $data): int
    {
        Policy::require('exhibition.edit', ['family_id' => $familyId, 'resource' => null], $user);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new HttpException(422, '请填写展馆标题');
        }
        $privacy = (string) ($data['privacy'] ?? 'family');
        if (!in_array($privacy, ['private', 'family', 'public'], true)) {
            throw new HttpException(422, '无效的隐私设置');
        }
        $id = Db::insert('exhibitions', [
            'family_id' => $familyId,
            'title' => mb_substr($title, 0, 180),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 4000),
            'theme' => mb_substr(trim((string) ($data['theme'] ?? '')), 0, 80),
            'privacy' => $privacy,
            'status' => 'draft',
            'owner_type' => 'family',
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        Audit::log('exhibition.created', 'exhibition', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM exhibitions WHERE id = ? LIMIT 1', [$id]);
    }

    public static function visible(?array $user, int $id): ?array
    {
        $exhibition = self::find($id);
        if ($exhibition === null) {
            return null;
        }
        $familyId = (int) $exhibition['family_id'];
        $member = Policy::familyRole($familyId, (int) ($user['id'] ?? 0)) !== null;
        if (!$member) {
            $family = FamilyService::find($familyId);
            if ($family === null || ($family['privacy'] ?? '') !== 'public'
                || ($exhibition['privacy'] ?? '') !== 'public' || ($exhibition['status'] ?? '') !== 'published') {
                return null;
            }
        }
        return $exhibition;
    }

    public static function listForFamily(?array $user, int $familyId): array
    {
        $member = Policy::familyRole($familyId, (int) ($user['id'] ?? 0)) !== null;
        $sql = 'SELECT * FROM exhibitions WHERE family_id = ?';
        if (!$member) {
            $sql .= " AND privacy = 'public' AND status = 'published'";
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        return Db::fetchAll($sql, [$familyId]);
    }

    public static function addItem(?array $user, int $exhibitionId, string $type, int $itemId, array $data = []): int
    {
        $exhibition = self::find($exhibitionId);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $familyId = (int) $exhibition['family_id'];
        Policy::require('exhibition.edit', ['family_id' => $familyId, 'resource' => null], $user);
        if (!in_array($type, self::ITEM_TYPES, true)) {
            throw new HttpException(422, '不支持的展馆内容类型');
        }
        if (!self::itemAccessible($user, $familyId, $type, $itemId)) {
            throw new HttpException(404, '内容不存在、跨家族或无权访问');
        }
        $id = Db::insert('exhibition_items', [
            'exhibition_id' => $exhibitionId,
            'item_type' => $type,
            'item_id' => $itemId,
            'section' => mb_substr(trim((string) ($data['section'] ?? 'default')) ?: 'default', 0, 80),
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'caption' => mb_substr(trim((string) ($data['caption'] ?? '')), 0, 255),
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        Audit::log('exhibition.item.added', 'exhibition_item', $id, [
            'exhibition_id' => $exhibitionId, 'item_type' => $type, 'item_id' => $itemId,
        ], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function hideItem(?array $user, int $itemId): void
    {
        $item = Db::fetch('SELECT * FROM exhibition_items WHERE id = ? LIMIT 1', [$itemId]);
        if ($item === null) {
            throw new HttpException(404, '展馆内容不存在');
        }
        $exhibition = self::find((int) $item['exhibition_id']);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        Policy::require('exhibition.edit', ['family_id' => (int) $exhibition['family_id'], 'resource' => null], $user);
        Db::update('exhibition_items', ['status' => 'hidden'], 'id = ?', [$itemId]);
        Audit::log('exhibition.item.hidden', 'exhibition_item', $itemId, [], (int) ($user['id'] ?? 0));
    }

    public static function items(int $exhibitionId): array
    {
        $rows = Db::fetchAll(
            'SELECT * FROM exhibition_items WHERE exhibition_id = ? AND status = ? ORDER BY section, sort_order, id',
            [$exhibitionId, 'active']
        );
        foreach ($rows as &$row) {
            $row['label'] = self::itemLabel((string) $row['item_type'], (int) $row['item_id']);
        }
        return $rows;
    }

    public static function publish(?array $user, int $id): void
    {
        $exhibition = self::find($id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $familyId = (int) $exhibition['family_id'];
        Policy::require('exhibition.publish', ['family_id' => $familyId, 'resource' => null], $user);
        foreach (self::items($id) as $item) {
            if (!self::itemVisible(null, $familyId, (string) $item['item_type'], (int) $item['item_id'])) {
                throw new HttpException(422, '展馆含有访客不可见的内容，请先移除或调整隐私');
            }
        }
        Db::update('exhibitions', [
            'status' => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        Audit::log('exhibition.published', 'exhibition', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
        NotificationService::emit($familyId, (int) $exhibition['created_by'], 'exhibition_review',
            '展馆已发布', '展馆《' . (string) $exhibition['title'] . '》已发布。', '/exhibitions/' . $id);
    }

    public static function offline(?array $user, int $id): void
    {
        $exhibition = self::find($id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        Policy::require('exhibition.publish', ['family_id' => (int) $exhibition['family_id'], 'resource' => null], $user);
        Db::update('exhibitions', ['status' => 'offline'], 'id = ?', [$id]);
        Audit::log('exhibition.offline', 'exhibition', $id, [], (int) ($user['id'] ?? 0));
    }

    /** 编辑期按编辑者视角校验内容归属与可见性。 */
    private static function itemAccessible(?array $user, int $familyId, string $type, int $itemId): bool
    {
        return match ($type) {
            'archive' => ($archive = ArchiveService::getVisible($user, $itemId)) !== null
                && (int) $archive['family_id'] === $familyId,
            'person' => ($person = FamilyPersonService::getVisible($user, $itemId)) !== null
                && (int) $person['family_id'] === $familyId,
            'event' => ($event = FamilyTimelineService::event($user, $itemId)) !== null
                && (int) $event['family_id'] === $familyId,
            'life_event' => Db::value(
                'SELECT le.id FROM life_events le JOIN memorials m ON m.id = le.memorial_id
                 WHERE le.id = ? AND m.verification_status = ? LIMIT 1',
                [$itemId, 'verified']
            ) !== null,
            default => false,
        };
    }

    /** 发布校验采用访客视角：私密档案/活人资料绝不能被公开展馆带出。 */
    private static function itemVisible(?array $user, int $familyId, string $type, int $itemId): bool
    {
        $user = null; // 发布校验始终以访客视角进行
        return match ($type) {
            'archive' => self::archivePublic($familyId, $itemId),
            'person' => self::personPublic($familyId, $itemId),
            'event' => Db::value(
                'SELECT id FROM family_events WHERE id = ? AND family_id = ? AND status = ? AND privacy = ?',
                [$itemId, $familyId, 'active', 'public']
            ) !== null,
            'life_event' => Db::value(
                "SELECT le.id FROM life_events le JOIN memorials m ON m.id = le.memorial_id
                 WHERE le.id = ? AND m.status = 'active' AND m.visibility = 'public' AND m.verification_status = 'verified'",
                [$itemId]
            ) !== null,
            default => false,
        };
    }

    private static function archivePublic(int $familyId, int $archiveId): bool
    {
        $archive = Db::fetch(
            "SELECT id FROM archives WHERE id = ? AND family_id = ? AND privacy = 'public' AND status = 'active' AND deleted_at IS NULL",
            [$archiveId, $familyId]
        );
        return $archive !== null;
    }

    private static function personPublic(int $familyId, int $personId): bool
    {
        $person = Db::fetch(
            "SELECT id, death_date FROM family_persons WHERE id = ? AND family_id = ? AND privacy = 'public'
             AND verification_status = 'verified' AND deleted_at IS NULL",
            [$personId, $familyId]
        );
        if ($person === null || empty($person['death_date'])) {
            return false; // 活人资料不得公开展示
        }
        return true;
    }

    private static function itemLabel(string $type, int $id): string
    {
        $table = match ($type) {
            'archive' => 'archives', 'person' => 'family_persons',
            'event' => 'family_events', 'life_event' => 'life_events', default => '',
        };
        if ($table === '') {
            return $type . ' #' . $id;
        }
        $title = Db::value('SELECT title FROM ' . $table . ' WHERE id = ? LIMIT 1', [$id]);
        if ($title === null && $table === 'family_persons') {
            $title = Db::value('SELECT name FROM family_persons WHERE id = ? LIMIT 1', [$id]);
        }
        return $type . ' #' . $id . ' · ' . (string) ($title ?? '');
    }
}
