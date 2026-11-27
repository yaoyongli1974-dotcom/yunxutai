<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Str;

/**
 * 家族基础服务（阶段 B）。Web 与 API 必须共用此类，权限统一走 Policy。
 */
final class FamilyService
{
    public const INVITE_DAYS = 7;

    /** 邀请可分配的角色：owner 不可通过邀请授予。 */
    public const INVITABLE_ROLES = ['admin', 'genealogy_editor', 'archive_editor', 'member', 'guest'];

    /** @param array<string,mixed> $user */
    public static function create(array $user, string $name, string $slug, array $data = []): int
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new HttpException(401, '请先登录');
        }
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '' || mb_strlen($name) > 190) {
            throw new HttpException(422, '家族名称不合法');
        }
        if (!Str::validSlug($slug)) {
            throw new HttpException(422, '家族标识只能包含小写字母、数字和连字符');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM families WHERE slug = ?', [$slug]) > 0) {
            throw new HttpException(409, '家族标识已被使用');
        }

        $privacy = (string) ($data['privacy'] ?? 'private');
        if (!in_array($privacy, ['private', 'unlisted', 'public'], true)) {
            throw new HttpException(422, '家族隐私设置不合法');
        }

        $familyId = Db::transaction(static function () use ($userId, $name, $slug, $data, $privacy): int {
            $id = Db::insert('families', [
                'name' => $name,
                'slug' => $slug,
                'surname' => self::nullable($data['surname'] ?? null, 50),
                'description' => self::nullable($data['description'] ?? null),
                'origin_place' => self::nullable($data['origin_place'] ?? null, 190),
                'privacy' => $privacy,
                'owner_user_id' => $userId,
                'status' => 'active',
            ]);
            Db::insert('family_members', [
                'family_id' => $id,
                'user_id' => $userId,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => date('Y-m-d H:i:s'),
                'invited_by' => null,
            ]);
            return $id;
        });

        Audit::log('family.create', 'family', $familyId, ['slug' => $slug], $userId);
        Policy::clearCache();
        return $familyId;
    }

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Db::fetch('SELECT * FROM families WHERE id = ? AND status = ? LIMIT 1', [$id, 'active']);
    }

    /** @param array<string,mixed> $user */
    public static function update(array $user, int $id, array $data): void
    {
        $family = self::requireFamily($id);
        Policy::require('family.manage', ['family_id' => $id, 'family' => $family], $user);

        $changes = [];
        foreach (['name', 'surname', 'description', 'origin_place'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = self::nullable($data[$field], $field === 'name' ? 190 : 190);
            }
        }
        if (isset($changes['name']) && trim((string) $changes['name']) === '') {
            throw new HttpException(422, '家族名称不能为空');
        }
        if (array_key_exists('privacy', $data)) {
            if (!in_array($data['privacy'], ['private', 'unlisted', 'public'], true)) {
                throw new HttpException(422, '家族隐私设置不合法');
            }
            $changes['privacy'] = $data['privacy'];
        }
        if (array_key_exists('status', $data)) {
            if (!in_array($data['status'], ['active', 'suspended', 'archived'], true)) {
                throw new HttpException(422, '家族状态不合法');
            }
            $changes['status'] = $data['status'];
        }
        if (array_key_exists('slug', $data)) {
            $slug = strtolower(trim((string) $data['slug']));
            if (!Str::validSlug($slug)) {
                throw new HttpException(422, '家族标识不合法');
            }
            $other = (int) Db::value('SELECT COUNT(*) FROM families WHERE slug = ? AND id <> ?', [$slug, $id]);
            if ($other > 0) {
                throw new HttpException(409, '家族标识已被使用');
            }
            $changes['slug'] = $slug;
        }
        if ($changes === []) {
            return;
        }
        Db::update('families', $changes, 'id = ?', [$id]);
        Audit::log('family.update', 'family', $id, ['fields' => array_keys($changes)], (int) $user['id']);
        Policy::clearCache();
    }

    /**
     * 创建邀请。仅返回一次明文令牌，数据库只保存 sha256。
     * @param array<string,mixed> $user
     * @return array{token:string, expires_at:string}
     */
    public static function invite(array $user, int $familyId, string $email, string $role): array
    {
        $family = self::requireFamily($familyId);
        Policy::require('family.members.manage', ['family_id' => $familyId, 'family' => $family], $user);
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, '邮箱地址不合法');
        }
        if (!in_array($role, self::INVITABLE_ROLES, true)) {
            throw new HttpException(422, '邀请角色不合法');
        }
        $existing = (int) Db::value(
            'SELECT COUNT(*) FROM family_members WHERE family_id = ? AND status = ? AND user_id IN
             (SELECT id FROM users WHERE LOWER(email) = ?)',
            [$familyId, 'active', $email]
        );
        if ($existing > 0) {
            throw new HttpException(409, '该用户已是家族成员');
        }
        $token = Str::random(32);
        $expiresAt = date('Y-m-d H:i:s', time() + self::INVITE_DAYS * 86400);
        Db::transaction(static function () use ($familyId, $email, $role, $token, $expiresAt, $user): void {
            Db::update(
                'family_invitations',
                ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
                'family_id = ? AND LOWER(email) = ? AND status = ?',
                [$familyId, $email, 'pending']
            );
            Db::insert('family_invitations', [
                'family_id' => $familyId,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'invited_by' => (int) $user['id'],
            ]);
        });
        Audit::log('family.invite', 'family', $familyId, ['email_hash' => hash('sha256', $email), 'role' => $role], (int) $user['id']);
        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** 接受邀请：校验令牌、邮箱、有效期与家族状态；撤销/过期令牌不可复用。 */
    public static function acceptInvitation(array $user, string $token): int
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $token === '') {
            throw new HttpException(404, '邀请不存在或已失效');
        }
        $invitation = Db::fetch(
            'SELECT i.*, f.status AS family_status FROM family_invitations i
             JOIN families f ON f.id = i.family_id
             WHERE i.token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($invitation === null || ($invitation['status'] ?? '') !== 'pending'
            || ($invitation['family_status'] ?? '') !== 'active'
            || strtotime((string) $invitation['expires_at']) < time()
            || strtolower((string) $user['email']) !== strtolower((string) $invitation['email'])) {
            throw new HttpException(404, '邀请不存在或已失效');
        }

        $familyId = (int) $invitation['family_id'];
        Db::transaction(static function () use ($invitation, $familyId, $userId): void {
            $member = Db::fetch(
                'SELECT id, status FROM family_members WHERE family_id = ? AND user_id = ? LIMIT 1',
                [$familyId, $userId]
            );
            if ($member !== null && in_array($member['status'], ['left', 'banned'], true)) {
                throw new HttpException(404, '邀请不存在或已失效');
            }
            if ($member === null) {
                Db::insert('family_members', [
                    'family_id' => $familyId,
                    'user_id' => $userId,
                    'role' => $invitation['role'],
                    'status' => 'active',
                    'joined_at' => date('Y-m-d H:i:s'),
                    'invited_by' => $invitation['invited_by'],
                ]);
            } else {
                Db::update(
                    'family_members',
                    ['status' => 'active', 'role' => $invitation['role'], 'joined_at' => date('Y-m-d H:i:s')],
                    'id = ?',
                    [(int) $member['id']]
                );
            }
            Db::update(
                'family_invitations',
                ['status' => 'accepted', 'accepted_by' => $userId, 'accepted_at' => date('Y-m-d H:i:s')],
                'id = ? AND status = ?',
                [(int) $invitation['id'], 'pending']
            );
        });
        Audit::log('family.invitation.accepted', 'family', $familyId, [], $userId);
        Policy::clearCache();
        return $familyId;
    }

    /** @param array<string,mixed> $user */
    public static function revokeInvitation(array $user, int $invitationId): void
    {
        $invitation = Db::fetch('SELECT * FROM family_invitations WHERE id = ? LIMIT 1', [$invitationId]);
        if ($invitation === null) {
            throw new HttpException(404, '邀请不存在');
        }
        $familyId = (int) $invitation['family_id'];
        $family = self::requireFamily($familyId);
        Policy::require('family.members.manage', ['family_id' => $familyId, 'family' => $family], $user);
        if (($invitation['status'] ?? '') !== 'pending') {
            throw new HttpException(409, '该邀请已处理');
        }
        Db::update(
            'family_invitations',
            ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$invitationId]
        );
        Audit::log('family.invitation.revoked', 'family', $familyId, ['invitation_id' => $invitationId], (int) $user['id']);
        Policy::clearCache();
    }

    /** @return list<array<string,mixed>> */
    public static function members(array $user, int $familyId): array
    {
        $family = self::requireFamily($familyId);
        Policy::require('family.members.manage', ['family_id' => $familyId, 'family' => $family], $user);
        return Db::fetchAll(
            'SELECT fm.id, fm.user_id, fm.role, fm.status, fm.joined_at, u.email, u.display_name
             FROM family_members fm JOIN users u ON u.id = fm.user_id
             WHERE fm.family_id = ? ORDER BY fm.id',
            [$familyId]
        );
    }

    /** 角色调整：owner 不可被降级，禁止修改自己，禁止前端决定角色。 */
    public static function updateRole(array $user, int $familyId, int $targetUserId, string $role): void
    {
        $family = self::requireFamily($familyId);
        Policy::require('family.members.manage', ['family_id' => $familyId, 'family' => $family], $user);
        if (!in_array($role, Policy::FAMILY_ROLES, true)) {
            throw new HttpException(422, '家族角色不合法');
        }
        $member = Db::fetch(
            'SELECT * FROM family_members WHERE family_id = ? AND user_id = ? LIMIT 1',
            [$familyId, $targetUserId]
        );
        if ($member === null || ($member['status'] ?? '') !== 'active') {
            throw new HttpException(404, '成员不存在');
        }
        if ($member['role'] === 'owner' || (int) $user['id'] === $targetUserId) {
            throw new HttpException(403, '不允许调整该成员角色');
        }
        Db::update('family_members', ['role' => $role], 'id = ?', [(int) $member['id']]);
        Audit::log('family.member.role', 'family', $familyId, [
            'user_id' => $targetUserId, 'from' => $member['role'], 'to' => $role,
        ], (int) $user['id']);
        Policy::clearCache();
    }

    /** @param array<string,mixed> $user */
    public static function removeMember(array $user, int $familyId, int $targetUserId): void
    {
        $family = self::requireFamily($familyId);
        Policy::require('family.members.manage', ['family_id' => $familyId, 'family' => $family], $user);
        $member = Db::fetch(
            'SELECT * FROM family_members WHERE family_id = ? AND user_id = ? LIMIT 1',
            [$familyId, $targetUserId]
        );
        if ($member === null || ($member['status'] ?? '') !== 'active') {
            throw new HttpException(404, '成员不存在');
        }
        if ($member['role'] === 'owner') {
            throw new HttpException(403, '不能移除家族创建者');
        }
        Db::update('family_members', ['status' => 'left'], 'id = ?', [(int) $member['id']]);
        Audit::log('family.member.removed', 'family', $familyId, ['user_id' => $targetUserId], (int) $user['id']);
        Policy::clearCache();
    }

    /** 主动退出；owner 需先转让，不允许直接退出造成无主家族。 */
    public static function leave(array $user, int $familyId): void
    {
        self::requireFamily($familyId);
        $role = Policy::familyRole($familyId, (int) ($user['id'] ?? 0));
        if ($role === null) {
            throw new HttpException(404, '家族不存在或无权访问');
        }
        if ($role === 'owner') {
            throw new HttpException(403, '请先转让家族管理权');
        }
        Db::update(
            'family_members',
            ['status' => 'left'],
            'family_id = ? AND user_id = ? AND status = ?',
            [$familyId, (int) $user['id'], 'active']
        );
        Audit::log('family.member.left', 'family', $familyId, [], (int) $user['id']);
        Policy::clearCache();
    }

    /** @return list<array<string,mixed>> 当前用户加入的家族（平台 admin 无隐式成员资格） */
    public static function forUser(?array $user): array
    {
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            return [];
        }
        return Db::fetchAll(
            'SELECT f.*, fm.role AS member_role, fm.status AS member_status
             FROM family_members fm JOIN families f ON f.id = fm.family_id
             WHERE fm.user_id = ? AND fm.status = ? AND f.status = ?
             ORDER BY f.id',
            [$userId, 'active', 'active']
        );
    }

    private static function requireFamily(int $id): array
    {
        $family = self::find($id);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        return $family;
    }

    private static function nullable(mixed $value, int $max = 65535): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        return mb_substr($value, 0, $max);
    }
}
