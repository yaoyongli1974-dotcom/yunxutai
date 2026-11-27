<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;

/** 家族时间轴：事件可关联多个人物，保留来源与隐私。 */
final class FamilyTimelineService
{
    public const SOURCES = ['user_input', 'archive', 'oral_history', 'person_profile', 'imported'];

    public static function create(?array $user, int $familyId, array $data): int
    {
        Policy::require('timeline.edit', ['family_id' => $familyId, 'resource' => null], $user);
        $title = trim((string) ($data['title'] ?? ''));
        $date = trim((string) ($data['event_date'] ?? ''));
        if ($title === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new HttpException(422, '请填写事件标题与有效日期');
        }
        $privacy = (string) ($data['privacy'] ?? 'family');
        if (!in_array($privacy, ['private', 'family', 'public'], true)) {
            throw new HttpException(422, '无效的隐私设置');
        }
        $source = (string) ($data['source_type'] ?? 'user_input');
        if (!in_array($source, self::SOURCES, true)) {
            throw new HttpException(422, '无效的事件来源');
        }
        $id = Db::transaction(static function () use ($user, $familyId, $data, $title, $date, $privacy, $source): int {
            $eventId = Db::insert('family_events', [
                'family_id' => $familyId,
                'title' => mb_substr($title, 0, 180),
                'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 4000),
                'event_date' => $date,
                'end_date' => !empty($data['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['end_date']) === 1 ? (string) $data['end_date'] : null,
                'location' => mb_substr(trim((string) ($data['location'] ?? '')), 0, 180),
                'event_type' => mb_substr(trim((string) ($data['event_type'] ?? 'other')) ?: 'other', 0, 40),
                'privacy' => $privacy,
                'source_type' => $source,
                'source_id' => !empty($data['source_id']) ? (int) $data['source_id'] : null,
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            foreach (($data['person_ids'] ?? []) as $personId) {
                $personId = (int) $personId;
                if ($personId > 0 && FamilyPersonService::getVisible($user, $personId) !== null) {
                    Db::insert('family_event_persons', [
                        'event_id' => $eventId, 'person_id' => $personId,
                        'created_by' => (int) ($user['id'] ?? 0),
                    ]);
                }
            }
            return $eventId;
        });
        Audit::log('timeline.event.created', 'family_event', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
        return $id;
    }

    /** 访客只能看到公开家族中的公开事件；成员按角色可见。 */
    public static function events(?array $user, int $familyId, string $type = '', int $limit = 200): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            return [];
        }
        $member = Policy::familyRole($familyId, (int) ($user['id'] ?? 0)) !== null;
        $sql = 'SELECT e.* FROM family_events e WHERE e.family_id = ? AND e.status = ?';
        $params = [$familyId, 'active'];
        if (!$member) {
            if (($family['privacy'] ?? '') !== 'public') {
                return [];
            }
            $sql .= " AND e.privacy = 'public'";
        }
        if ($type !== '') {
            $sql .= ' AND e.event_type = ?';
            $params[] = mb_substr($type, 0, 40);
        }
        $sql .= ' ORDER BY e.event_date DESC, e.id DESC LIMIT ' . max(1, min(500, $limit));
        return Db::fetchAll($sql, $params);
    }

    public static function event(?array $user, int $id): ?array
    {
        $event = Db::fetch('SELECT * FROM family_events WHERE id = ? AND status = ? LIMIT 1', [$id, 'active']);
        if ($event === null) {
            return null;
        }
        foreach (self::events($user, (int) $event['family_id']) as $visible) {
            if ((int) $visible['id'] === $id) {
                return $event;
            }
        }
        return null;
    }

    public static function persons(int $eventId): array
    {
        return Db::fetchAll(
            'SELECT p.id, p.name FROM family_event_persons ep
             JOIN family_persons p ON p.id = ep.person_id
             WHERE ep.event_id = ? AND p.deleted_at IS NULL ORDER BY p.id',
            [$eventId]
        );
    }

    public static function archive(?array $user, int $id): void
    {
        $event = Db::fetch('SELECT * FROM family_events WHERE id = ? LIMIT 1', [$id]);
        if ($event === null) {
            throw new HttpException(404, '事件不存在');
        }
        Policy::require('timeline.edit', ['family_id' => (int) $event['family_id'], 'resource' => null], $user);
        Db::update('family_events', ['status' => 'archived'], 'id = ?', [$id]);
        Audit::log('timeline.event.archived', 'family_event', $id, ['family_id' => (int) $event['family_id']], (int) ($user['id'] ?? 0));
    }

    /** 纪念日提醒：只发给事件创建者， preference 由 NotificationService 决定。 */
    public static function anniversariesToday(): array
    {
        $rows = Db::fetchAll(
            "SELECT id, family_id, title, created_by FROM family_events
             WHERE status = 'active' AND DATE_FORMAT(event_date, '%m-%d') = CURDATE()"
        );
        $sent = 0;
        foreach ($rows as $row) {
            NotificationService::emit(
                (int) $row['family_id'],
                (int) $row['created_by'],
                'anniversary',
                '纪念日提醒：' . (string) $row['title'],
                '今天是家族记录中的纪念日，可前往家族时间轴回顾。',
                '/families/' . (int) $row['family_id'] . '/timeline'
            );
            $sent++;
        }
        return ['checked' => count($rows), 'sent' => $sent];
    }
}
