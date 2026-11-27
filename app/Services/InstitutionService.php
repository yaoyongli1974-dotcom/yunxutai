<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;

/**
 * 机构租户能力预留（阶段 G 补齐）。
 *
 * 仅平台管理员可创建机构、任命机构管理员、把家族挂到机构下。
 * 机构数据与普通家族数据通过 family_institutions 显式关联，不做隐式归属，
 * 也不改变现有家族权限判断；后续机构后台必须基于本服务的角色校验扩展。
 */
final class InstitutionService
{
    public static function create(array $user, string $name, string $slug, array $config = []): int
    {
        self::requirePlatformAdmin($user);
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '' || mb_strlen($name) > 190 || !Str::validSlug($slug)) {
            throw new HttpException(422, '机构名称或标识不合法');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM institutions WHERE slug = ?', [$slug]) > 0) {
            throw new HttpException(409, '机构标识已被使用');
        }
        $id = Db::insert('institutions', [
            'name' => $name,
            'slug' => $slug,
            'config_json' => $config === [] ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'created_by' => (int) $user['id'],
        ]);
        Db::insert('institution_members', [
            'institution_id' => $id,
            'user_id' => (int) $user['id'],
            'role' => 'owner',
            'status' => 'active',
        ]);
        Audit::log('institution.created', 'institution', $id, ['slug' => $slug], (int) $user['id']);
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM institutions WHERE id = ? AND status = ? LIMIT 1', [$id, 'active']);
    }

    public static function members(int $institutionId): array
    {
        return Db::fetchAll(
            'SELECT m.id, m.role, m.status, u.id AS user_id, u.email, u.display_name
             FROM institution_members m INNER JOIN users u ON u.id = m.user_id
             WHERE m.institution_id = ? AND m.status = ? ORDER BY m.id',
            [$institutionId, 'active']
        );
    }

    public static function setRole(array $user, int $institutionId, int $targetUserId, string $role): void
    {
        self::requirePlatformAdmin($user);
        if (!in_array($role, ['owner', 'admin', 'member'], true)) {
            throw new HttpException(422, '无效的机构角色');
        }
        if (self::find($institutionId) === null) {
            throw new HttpException(404, '机构不存在');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM institution_members WHERE institution_id = ? AND user_id = ? AND status = ?', [$institutionId, $targetUserId, 'active']) === 0) {
            throw new HttpException(422, '该用户不是机构成员');
        }
        Db::update('institution_members', ['role' => $role], 'institution_id = ? AND user_id = ?', [$institutionId, $targetUserId]);
        Audit::log('institution.role_changed', 'institution', $institutionId, [
            'user_id' => $targetUserId, 'role' => $role,
        ], (int) $user['id']);
    }

    /** 家族与机构显式关联；一个家族只归属一个机构，不修改 families 原表。 */
    public static function attachFamily(array $user, int $institutionId, int $familyId): void
    {
        self::requirePlatformAdmin($user);
        if (self::find($institutionId) === null) {
            throw new HttpException(404, '机构不存在');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM families WHERE id = ?', [$familyId]) === 0) {
            throw new HttpException(404, '家族不存在');
        }
        Db::query(
            'INSERT INTO family_institutions (family_id, institution_id, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE institution_id = VALUES(institution_id)',
            [$familyId, $institutionId]
        );
        Audit::log('institution.family_attached', 'family', $familyId, [
            'institution_id' => $institutionId,
        ], (int) $user['id']);
    }

    public static function institutionOfFamily(int $familyId): ?array
    {
        $row = Db::fetch(
            'SELECT i.* FROM family_institutions fi INNER JOIN institutions i ON i.id = fi.institution_id
             WHERE fi.family_id = ? AND i.status = ? LIMIT 1',
            [$familyId, 'active']
        );
        return $row;
    }

    private static function requirePlatformAdmin(array $user): void
    {
        $current = $user['id'] ?? (Auth::user()['id'] ?? 0);
        if ((int) $current <= 0) {
            throw new HttpException(401, '请先登录');
        }
        $row = Db::fetch('SELECT role FROM users WHERE id = ? LIMIT 1', [(int) $current]);
        if ($row === null || (string) $row['role'] !== 'admin') {
            throw new HttpException(403, '仅平台管理员可管理机构');
        }
    }
}
