<?php
declare(strict_types=1);

namespace App\Core;

final class Policy
{
    /** @var array<string, string|null> */
    private static array $roles = [];

    public static function memberRole(int $memorialId, ?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        $key = $memorialId . ':' . $userId;
        if (array_key_exists($key, self::$roles)) {
            return self::$roles[$key];
        }
        $role = Db::value(
            'SELECT role FROM memorial_members WHERE memorial_id = ? AND user_id = ? AND status = ? LIMIT 1',
            [$memorialId, $userId, 'active']
        );
        return self::$roles[$key] = ($role !== null ? (string) $role : null);
    }

    public static function role(array $memorial, ?array $user): ?string
    {
        if (!$user) {
            return null;
        }
        if (($user['role'] ?? '') === 'admin') {
            return 'admin';
        }
        return self::memberRole((int) $memorial['id'], (int) $user['id']);
    }

    public static function canView(array $memorial, ?array $user): bool
    {
        $role = self::role($memorial, $user);
        if ($role !== null) {
            return true;
        }
        if (($memorial['status'] ?? '') !== 'active') {
            return false;
        }
        // 未完成身份材料核验的纪念馆只能以私密方式存在，即使误将 visibility 改为公开也不外显。
        if (($memorial['verification_status'] ?? 'none') !== 'verified') {
            return false;
        }
        return in_array($memorial['visibility'] ?? '', ['public', 'unlisted'], true);
    }

    public static function canEdit(array $memorial, ?array $user): bool
    {
        $role = self::role($memorial, $user);
        if (in_array($role, ['admin', 'owner', 'editor'], true)) {
            return true;
        }
        return false;
    }

    public static function canManage(array $memorial, ?array $user): bool
    {
        return in_array(self::role($memorial, $user), ['admin', 'owner'], true);
    }

    public static function canModerate(array $memorial, ?array $user): bool
    {
        return in_array(self::role($memorial, $user), ['admin', 'owner', 'editor'], true);
    }

    public static function canPost(array $memorial, ?array $user): bool
    {
        if (!$user || ($memorial['status'] ?? '') !== 'active') {
            return false;
        }
        if (!self::canView($memorial, $user)) {
            return false;
        }
        if (($memorial['visibility'] ?? '') === 'private') {
            return self::memberRole((int) $memorial['id'], (int) $user['id']) !== null;
        }
        return true;
    }

    public static function canViewMedia(array $media, array $memorial, ?array $user): bool
    {
        if (!self::canView($memorial, $user)) {
            return false;
        }
        if (($media['visibility'] ?? 'visitors') === 'members') {
            return self::role($memorial, $user) !== null;
        }
        return true;
    }

    public static function isIndexable(array $memorial): bool
    {
        return ($memorial['status'] ?? '') === 'active'
            && ($memorial['verification_status'] ?? 'none') === 'verified'
            && ($memorial['visibility'] ?? '') === 'public'
            && (int) ($memorial['allow_search_index'] ?? 0) === 1;
    }


    /** 家族角色（PROPOSED，阶段 B）。角色只能由后端流程写入 family_members。 */
    public const FAMILY_ROLES = ['owner', 'admin', 'genealogy_editor', 'archive_editor', 'member', 'guest'];

    /** 能力 → 允许角色（PROPOSED，阶段 B）。默认拒绝：表中没有的能力一律不可访问。 */
    public const FAMILY_CAPABILITIES = [
        'family.view' => self::FAMILY_ROLES,
        'family.manage' => ['owner', 'admin'],
        'family.members.manage' => ['owner', 'admin'],
        'person.view' => self::FAMILY_ROLES,
        'person.edit' => ['owner', 'admin', 'genealogy_editor'],
        'person.merge' => ['owner', 'admin'],
        'relationship.submit' => self::FAMILY_ROLES,
        'relationship.review' => ['owner', 'admin'],
        'archive.view' => self::FAMILY_ROLES,
        'archive.upload' => ['owner', 'admin', 'archive_editor'],
        'archive.edit' => ['owner', 'admin', 'archive_editor'],
        'archive.delete.request' => self::FAMILY_ROLES,
        'archive.delete.approve' => ['owner', 'admin'],
        'file.download' => self::FAMILY_ROLES,
        'ai.workflow.create' => ['owner', 'admin', 'genealogy_editor', 'archive_editor'],
        'ai.result.review' => ['owner', 'admin'],
        'oral_history.consent.manage' => ['owner', 'admin'],
        'exhibition.edit' => ['owner', 'admin', 'archive_editor'],
        'exhibition.publish' => ['owner', 'admin'],
        'data.export' => ['owner', 'admin'],
    ];

    /** 资源类型 → 表名白名单，禁止外部拼接表名。 */
    private const RESOURCE_TABLES = [
        'family' => 'families',
        'person' => 'family_persons',
    ];

    /**
     * 用户在该家族的有效角色；仅 active 成员 + active 家族，退出/封禁/撤销邀请立即失效。
     */
    public static function familyRole(int $familyId, ?int $userId): ?string
    {
        if ($familyId <= 0 || !$userId) {
            return null;
        }
        $key = 'family:' . $familyId . ':' . $userId;
        if (array_key_exists($key, self::$roles)) {
            $role = self::$roles[$key];
            return $role === null ? null : (string) $role;
        }
        $role = Db::value(
            'SELECT fm.role FROM family_members fm
             JOIN families f ON f.id = fm.family_id
             WHERE fm.family_id = ? AND fm.user_id = ? AND fm.status = ? AND f.status = ?
             LIMIT 1',
            [$familyId, $userId, 'active', 'active']
        );
        return self::$roles[$key] = ($role !== null ? (string) $role : null);
    }

    /**
     * Web 与 API 共用的统一授权入口。context 至少含 family_id，可带 resource 做资源级复核。
     * 平台 admin 不自动获得家族权限（阶段 A 设计第 7 节）。
     */
    public static function can(string $capability, array $context, ?array $user): bool
    {
        $allowedRoles = self::FAMILY_CAPABILITIES[$capability] ?? null;
        $familyId = (int) ($context['family_id'] ?? 0);
        if ($allowedRoles === null || $familyId <= 0) {
            return false;
        }
        $family = $context['family'] ?? null;
        if (!is_array($family)) {
            $family = Db::fetch(
                'SELECT id, privacy, status FROM families WHERE id = ? AND status = ? LIMIT 1',
                [$familyId, 'active']
            );
        }
        if (!is_array($family) || (int) ($family['id'] ?? 0) !== $familyId) {
            return false;
        }

        $role = self::familyRole($familyId, isset($user['id']) ? (int) $user['id'] : null);
        if ($capability === 'family.view') {
            if ($role !== null) {
                return true;
            }
            // unlisted 视为需授权：不能仅凭知道链接访问。
            return ($family['privacy'] ?? '') === 'public';
        }
        if ($role === null || !in_array($role, $allowedRoles, true)) {
            return false;
        }
        if ($capability === 'file.download' && !array_key_exists('resource', $context)) {
            // 文件类能力必须附带具体资源，避免角色即等于“可下载全部”。
            return false;
        }
        if (isset($context['resource']) && str_ends_with($capability, '.view')) {
            $resource = $context['resource'];
            if (!is_array($resource) || !self::canViewResource(
                (string) ($resource['type'] ?? ''),
                (int) ($resource['id'] ?? 0),
                isset($user['id']) ? (int) $user['id'] : null
            )) {
                return false;
            }
        }
        return true;
    }

    /** 授权失败统一按“不存在”处理，避免泄露资源是否存在。 */
    public static function require(string $capability, array $context, ?array $user): void
    {
        if (!self::can($capability, $context, $user)) {
            throw new HttpException(404, '资源不存在或无权访问');
        }
    }

    /**
     * 资源级可见性：家族/人物。资源类型走白名单，查询强制带 family_id。
     */
    public static function canViewResource(string $type, int $id, ?int $userId): bool
    {
        $table = self::RESOURCE_TABLES[$type] ?? null;
        if ($table === null || $id <= 0) {
            return false;
        }
        $sql = 'SELECT * FROM ' . $table . ' WHERE id = ?';
        $sql .= $type === 'person' ? ' AND deleted_at IS NULL' : " AND status = 'active'";
        $resource = Db::fetch($sql . ' LIMIT 1', [$id]);
        if ($resource === null) {
            return false;
        }
        $familyId = (int) $resource['family_id'];
        $family = Db::fetch(
            "SELECT privacy, status FROM families WHERE id = ? AND status = 'active' LIMIT 1",
            [$familyId]
        );
        if ($family === null) {
            return false;
        }
        $role = self::familyRole($familyId, $userId);
        $familyVisible = $role !== null || ($family['privacy'] ?? '') === 'public';
        if (!$familyVisible) {
            return false;
        }

        if ($type === 'family') {
            return true;
        }

        // 活人资料默认私密；争议资料仅管理员可见。
        if (($resource['verification_status'] ?? 'none') === 'disputed') {
            return in_array($role, ['owner', 'admin'], true);
        }
        if ((int) ($resource['is_deceased'] ?? 0) === 0) {
            return in_array($role, ['owner', 'admin', 'genealogy_editor'], true);
        }
        $privacy = (string) ($resource['privacy'] ?? 'private');
        if ($privacy === 'private') {
            return in_array($role, ['owner', 'admin', 'genealogy_editor'], true);
        }
        if ($privacy === 'family') {
            return $role !== null;
        }
        // public 人物仍需已核验，且所属家族对访问者可见。
        return $privacy === 'public' && ($resource['verification_status'] ?? 'none') === 'verified';
    }

    public static function clearCache(): void
    {
        self::$roles = [];
    }
}
