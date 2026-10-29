<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Api\Presenter;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Memorial;

/**
 * 馆主成员管理（小程序 API）。
 * 业务语义与网页端 App\Controllers\Manage\MemberController 保持一致：
 * 仅域内 owner / 平台 admin 可操作；已登录但非管理者返回 403，
 * 馆不存在或请求者不在成员列表统一 404（不泄露存在性）。
 */
final class MemberController extends BaseController
{
    private const ROLES = ['editor', 'viewer'];

    /**
     * GET /api/v1/manage/memorials/{id}/members
     */
    public function index(Request $request, string $id): Response
    {
        [$memorial] = $this->loadManageable((int) $id);

        $items = array_map(static fn (array $member): array => [
            'member_id' => (int) $member['id'],
            'user_id' => (int) $member['user_id'],
            'role' => (string) $member['role'],
            'status' => (string) $member['status'],
            'display_name' => (string) $member['display_name'],
            'email' => Presenter::maskEmail($member['email'] ?? ''),
            'joined_at' => $member['created_at'] ?? null,
        ], Member::all((int) $memorial['id']));

        $pending = array_map(
            fn (array $invitation): array => $this->presentInvitation($invitation),
            Invitation::pendingFor((int) $memorial['id'])
        );

        return Api::ok([
            'items' => $items,
            'pending' => $pending,
        ]);
    }

    /**
     * POST /api/v1/manage/memorials/{id}/members/invite
     */
    public function invite(Request $request, string $id): Response
    {
        [$memorial, $user] = $this->loadManageable((int) $id);

        $email = mb_strtolower(Input::string($request, 'email'));
        $role = Input::string($request, 'role');

        $fields = [];
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fields['email'] = '邮箱格式不正确';
            } elseif (mb_strlen($email) > 190) {
                $fields['email'] = '邮箱长度不能超过 190 个字符';
            }
        }
        if (!in_array($role, self::ROLES, true)) {
            $fields['role'] = '角色只能是协作者或亲友';
        }
        if ($fields !== []) {
            throw ApiException::validation('邀请信息不完整', $fields);
        }

        if ($email !== '') {
            $existing = Db::value(
                'SELECT id FROM invitations
                 WHERE memorial_id = ? AND email = ? AND accepted_at IS NULL
                   AND revoked_at IS NULL AND expires_at > NOW()
                 LIMIT 1',
                [(int) $memorial['id'], $email]
            );
            if ($existing !== null) {
                throw ApiException::validation('该邮箱已有待接受的邀请，请勿重复发送', [
                    'email' => '已有待接受的邀请',
                ]);
            }
        }

        $invitation = Invitation::create(
            (int) $memorial['id'],
            $email !== '' ? $email : null,
            $role,
            (int) $user['id'],
            7
        );

        $row = Db::fetch('SELECT id, token, expires_at FROM invitations WHERE id = ? LIMIT 1', [(int) $invitation['id']]);
        if ($row === null) {
            throw new ApiException('internal_error', '邀请创建后读取失败', 500);
        }

        Audit::log('member.invite', 'invitation', (int) $row['id'], [
            'memorial_id' => (int) $memorial['id'],
            'role' => $role,
            'email' => $email !== '' ? $email : null,
        ]);

        return Api::ok([
            'invitation_id' => (int) $row['id'],
            'invite_url' => $this->inviteUrl((string) $row['token']),
            'expires_at' => $row['expires_at'],
        ], 201);
    }

    /**
     * POST /api/v1/manage/memorials/{id}/members/{memberId}/role
     */
    public function updateRole(Request $request, string $id, string $memberId): Response
    {
        [$memorial] = $this->loadManageable((int) $id);
        $member = $this->loadMember((int) $memberId, (int) $memorial['id']);

        // owner 成员不可改角色；与「不存在/已移除」统一 404，避免暴露成员类型。
        if ($member['role'] === 'owner') {
            throw ApiException::notFound('成员不存在');
        }

        $role = Input::string($request, 'role');
        if (!in_array($role, self::ROLES, true)) {
            throw ApiException::validation('角色只能是协作者或亲友', [
                'role' => '角色只能是协作者或亲友',
            ]);
        }

        Member::updateRole((int) $member['id'], $role);
        Audit::log('member.update_role', 'memorial_member', (int) $member['id'], [
            'memorial_id' => (int) $memorial['id'],
            'from' => $member['role'],
            'to' => $role,
        ]);

        return Api::ok([
            'member_id' => (int) $member['id'],
            'role' => $role,
        ]);
    }

    /**
     * POST /api/v1/manage/memorials/{id}/members/{memberId}/remove
     */
    public function remove(Request $request, string $id, string $memberId): Response
    {
        [$memorial, $user] = $this->loadManageable((int) $id);
        $member = $this->loadMember((int) $memberId, (int) $memorial['id']);

        if ($member['role'] === 'owner') {
            throw ApiException::validation('馆主不能被移除');
        }
        if ((int) $member['user_id'] === (int) $user['id']) {
            throw ApiException::validation('不能移除自己');
        }

        Member::revoke((int) $member['id']);
        Audit::log('member.revoke', 'memorial_member', (int) $member['id'], [
            'memorial_id' => (int) $memorial['id'],
            'user_id' => (int) $member['user_id'],
            'role' => $member['role'],
        ]);

        return Api::ok(['removed' => true]);
    }

    /**
     * POST /api/v1/manage/memorials/{id}/invitations/{invitationId}/revoke
     */
    public function revokeInvitation(Request $request, string $id, string $invitationId): Response
    {
        [$memorial] = $this->loadManageable((int) $id);

        $invitation = Db::fetch(
            'SELECT * FROM invitations WHERE id = ? AND memorial_id = ? LIMIT 1',
            [(int) $invitationId, (int) $memorial['id']]
        );
        if ($invitation === null || $invitation['revoked_at'] !== null) {
            throw ApiException::notFound('邀请不存在');
        }

        Invitation::revoke((int) $invitation['id'], (int) $memorial['id']);
        Audit::log('invitation.revoke', 'invitation', (int) $invitation['id'], [
            'memorial_id' => (int) $memorial['id'],
        ]);

        return Api::ok(['revoked' => true]);
    }

    /**
     * 与 web 端 loadManageable 同口径，但把「非成员」折叠为 404：
     * 已登录的 editor/viewer 属于可见成员，返回 403；非成员/馆不存在一律 404。
     *
     * @return array{0: array, 1: array}
     */
    private function loadManageable(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw ApiException::notFound('纪念馆不存在');
        }

        $user = $this->user();
        $role = Policy::role($memorial, $user);
        if (in_array($role, ['admin', 'owner'], true)) {
            return [$memorial, $user];
        }
        if ($role === null) {
            throw ApiException::notFound('纪念馆不存在');
        }
        throw ApiException::forbidden('只有馆主可以管理成员');
    }

    private function loadMember(int $memberId, int $memorialId): array
    {
        $member = Member::find($memberId);
        if ($member === null || (int) $member['memorial_id'] !== $memorialId || $member['status'] === 'revoked') {
            throw ApiException::notFound('成员不存在');
        }
        return $member;
    }

    private function presentInvitation(array $invitation): array
    {
        return [
            'invitation_id' => (int) $invitation['id'],
            'email' => $invitation['email'] !== null ? (string) $invitation['email'] : null,
            'role' => (string) $invitation['role'],
            'expires_at' => $invitation['expires_at'] ?? null,
            'invite_url' => $this->inviteUrl((string) $invitation['token']),
        ];
    }

    private function inviteUrl(string $token): string
    {
        return $this->baseUrl() . '/invitations/' . $token;
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base !== '') {
            return $base;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
