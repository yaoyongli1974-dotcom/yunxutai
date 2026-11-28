<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;

/**
 * 家族人物基础服务（阶段 B）：人物与登录用户分离，活人资料默认私密。
 */
final class FamilyPersonService
{
    /** @param array<string,mixed> $user */
    public static function create(array $user, int $familyId, array $data): int
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('person.edit', ['family_id' => $familyId, 'family' => $family], $user);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new HttpException(422, '人物姓名不合法');
        }
        $isDeceased = !empty($data['is_deceased']);
        $privacy = (string) ($data['privacy'] ?? 'private');
        if (!in_array($privacy, ['private', 'family', 'public'], true)) {
            throw new HttpException(422, '人物隐私设置不合法');
        }
        if (!$isDeceased && $privacy !== 'private') {
            throw new HttpException(422, '活人资料必须保持私密，并需单独授权后才能公开');
        }

        $id = Db::insert('family_persons', [
            'family_id' => $familyId,
            'name' => $name,
            'gender' => self::enum($data['gender'] ?? 'unknown', ['unknown', 'male', 'female', 'other'], 'unknown'),
            'birth_date' => self::date($data['birth_date'] ?? null),
            'death_date' => self::date($data['death_date'] ?? null),
            'birth_place' => self::text($data['birth_place'] ?? null, 190),
            'death_place' => self::text($data['death_place'] ?? null, 190),
            'biography' => self::text($data['biography'] ?? null),
            'privacy' => $privacy,
            // 公开仍须后续审核核验，创建时不得自动成为已确认事实。
            'verification_status' => 'none',
            'is_deceased' => $isDeceased ? 1 : 0,
            'created_by' => (int) $user['id'],
        ]);
        Audit::log('person.create', 'person', $id, ['family_id' => $familyId], (int) $user['id']);
        return $id;
    }

    /** 无权或不存在一律返回 null，避免通过错误差异探测资源存在性。 */
    public static function getVisible(?array $user, int $id): ?array
    {
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        if (!Policy::canViewResource('person', $id, $userId)) {
            return null;
        }
        return Db::fetch('SELECT * FROM family_persons WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
    }

    /**
     * 批量过滤已取出的人物行：一次家族查询 + 缓存的 familyRole，逐行复用
     * Policy::canViewResource('person') 的完全相同的可见性规则，保持输入顺序。
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function visibleRows(?array $user, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $familyIds = [];
        foreach ($rows as $row) {
            $familyId = (int) ($row['family_id'] ?? 0);
            if ($familyId > 0) {
                $familyIds[$familyId] = $familyId;
            }
        }
        $families = [];
        if ($familyIds !== []) {
            $placeholders = implode(',', array_fill(0, count($familyIds), '?'));
            foreach (Db::fetchAll(
                "SELECT id, privacy, status FROM families WHERE id IN ({$placeholders}) AND status = ?",
                [...array_values($familyIds), 'active']
            ) as $family) {
                $families[(int) $family['id']] = $family;
            }
        }
        $roles = [];
        foreach ($familyIds as $familyId) {
            $roles[$familyId] = Policy::familyRole($familyId, $userId);
        }

        $visible = [];
        foreach ($rows as $row) {
            if (self::rowVisible($row, $families, $roles)) {
                $visible[] = $row;
            }
        }
        return $visible;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $families
     * @param array<int,string|null> $roles
     */
    private static function rowVisible(array $row, array $families, array $roles): bool
    {
        $familyId = (int) ($row['family_id'] ?? 0);
        $family = $families[$familyId] ?? null;
        if ($family === null) {
            return false;
        }
        $role = $roles[$familyId] ?? null;
        // 家族必须可见：成员或公开家族；unlisted/private 仅成员可见。
        if ($role === null && ($family['privacy'] ?? '') !== 'public') {
            return false;
        }
        $verification = (string) ($row['verification_status'] ?? 'none');
        // 活人/争议资料与私密人物仅家族管理员可见，规则与 Policy 人物分支一致。
        if ($verification === 'disputed') {
            return in_array($role, ['owner', 'admin'], true);
        }
        if ((int) ($row['is_deceased'] ?? 0) === 0) {
            return in_array($role, ['owner', 'admin', 'genealogy_editor'], true);
        }
        $privacy = (string) ($row['privacy'] ?? 'private');
        if ($privacy === 'private') {
            return in_array($role, ['owner', 'admin', 'genealogy_editor'], true);
        }
        if ($privacy === 'family') {
            return $role !== null;
        }
        return $privacy === 'public' && $verification === 'verified';
    }

    /** @param array<string,mixed> $user */
    public static function update(array $user, int $familyId, int $personId, array $data): void
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('person.edit', ['family_id' => $familyId, 'family' => $family], $user);
        $person = Db::fetch(
            'SELECT * FROM family_persons WHERE id = ? AND family_id = ? AND deleted_at IS NULL LIMIT 1',
            [$personId, $familyId]
        );
        if ($person === null) {
            throw new HttpException(404, '人物不存在');
        }
        $changes = [];
        foreach (['name', 'birth_place', 'death_place', 'biography'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = self::text($data[$field], $field === 'name' ? 190 : 65535);
            }
        }
        if (isset($changes['name']) && $changes['name'] === null) {
            throw new HttpException(422, '人物姓名不能为空');
        }
        if (array_key_exists('gender', $data)) {
            $changes['gender'] = self::enum($data['gender'], ['unknown', 'male', 'female', 'other'], 'unknown');
        }
        foreach (['birth_date', 'death_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = self::date($data[$field]);
            }
        }
        if (array_key_exists('is_deceased', $data)) {
            $changes['is_deceased'] = !empty($data['is_deceased']) ? 1 : 0;
        }
        if (array_key_exists('privacy', $data)) {
            $privacy = (string) $data['privacy'];
            if (!in_array($privacy, ['private', 'family', 'public'], true)) {
                throw new HttpException(422, '人物隐私设置不合法');
            }
            $changes['privacy'] = $privacy;
        }
        $deceased = (int) ($changes['is_deceased'] ?? $person['is_deceased']) === 1;
        $privacy = (string) ($changes['privacy'] ?? $person['privacy']);
        if (!$deceased && $privacy !== 'private') {
            throw new HttpException(422, '活人资料必须保持私密，并需单独授权后才能公开');
        }
        if ($changes !== []) {
            Db::update('family_persons', $changes, 'id = ? AND family_id = ?', [$personId, $familyId]);
            Audit::log('person.update', 'person', $personId, [
                'family_id' => $familyId, 'fields' => array_keys($changes),
            ], (int) $user['id']);
        }
    }

    /** 关联已有纪念馆：保留原馆 ID，且关联者必须本身有权编辑该馆。 */
    public static function linkMemorial(array $user, int $familyId, int $personId, int $memorialId, ?string $note = null): int
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('person.edit', ['family_id' => $familyId, 'family' => $family], $user);
        $person = Db::fetch(
            'SELECT id FROM family_persons WHERE id = ? AND family_id = ? AND deleted_at IS NULL LIMIT 1',
            [$personId, $familyId]
        );
        $memorial = Db::fetch("SELECT * FROM memorials WHERE id = ? AND status = 'active' LIMIT 1", [$memorialId]);
        if ($person === null || $memorial === null || !Policy::canEdit($memorial, $user)) {
            throw new HttpException(404, '人物或纪念馆不存在');
        }
        $exists = (int) Db::value(
            'SELECT COUNT(*) FROM person_memorial_links WHERE person_id = ? AND memorial_id = ? AND status = ?',
            [$personId, $memorialId, 'active']
        );
        if ($exists > 0) {
            throw new HttpException(409, '该纪念馆已关联此人物');
        }
        $id = Db::insert('person_memorial_links', [
            'family_id' => $familyId,
            'person_id' => $personId,
            'memorial_id' => $memorialId,
            'relation_note' => self::text($note, 255),
            'created_by' => (int) $user['id'],
            'status' => 'active',
        ]);
        Audit::log('person.memorial.linked', 'person', $personId, [
            'family_id' => $familyId, 'memorial_id' => $memorialId,
        ], (int) $user['id']);
        return $id;
    }

    private static function enum(mixed $value, array $allowed, string $default): string
    {
        $value = (string) ($value ?? $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate(
            (int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)
        ) ? $value : null;
    }

    private static function text(mixed $value, int $max = 65535): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
