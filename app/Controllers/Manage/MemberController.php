<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Memorial;

final class MemberController
{
    public function index(Request $request, string $id): Response
    {
        $memorial = $this->loadManageable((int) $id);

        return $this->renderIndex($memorial, [], []);
    }

    public function invite(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadManageable((int) $id);

        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $role = (string) $request->input('role', 'viewer');

        $validator = Validator::make(
            ['email' => $email, 'role' => $role],
            ['email' => 'email|max:190', 'role' => 'required|in:editor,viewer'],
            ['email' => '受邀邮箱', 'role' => '角色']
        );

        $errors = $validator->errors();
        $old = ['email' => $email, 'role' => $role];

        if ($errors !== []) {
            return $this->renderIndex($memorial, $errors, $old, 422);
        }

        $invitation = Invitation::create(
            (int) $memorial['id'],
            $email !== '' ? $email : null,
            $role,
            (int) Auth::user()['id'],
            7
        );

        $link = $this->baseUrl() . '/invitations/' . $invitation['token'];
        Audit::log('member.invite', 'invitation', (int) $invitation['id'], [
            'memorial_id' => (int) $memorial['id'],
            'role' => $role,
            'email' => $email !== '' ? $email : null,
        ]);

        Flash::success('邀请已创建，7 天内有效。邀请链接：' . $link);
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
    }

    public function updateRole(Request $request, string $id, string $memberId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadManageable((int) $id);
        $member = $this->loadMember((int) $memberId, (int) $memorial['id']);

        $role = (string) $request->input('role', '');
        $old = ['member_id' => (int) $member['id'], 'role' => $role];

        if ($member['role'] === 'owner') {
            return $this->renderIndex($memorial, ['role' => '馆主角色不能修改。'], $old, 422);
        }
        if (!in_array($role, ['editor', 'viewer'], true)) {
            return $this->renderIndex($memorial, ['role' => '角色只能是协作者或亲友。'], $old, 422);
        }

        Member::updateRole((int) $member['id'], $role);
        Audit::log('member.update_role', 'memorial_member', (int) $member['id'], [
            'memorial_id' => (int) $memorial['id'],
            'from' => $member['role'],
            'to' => $role,
        ]);

        Flash::success('成员角色已更新。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
    }

    public function revoke(Request $request, string $id, string $memberId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadManageable((int) $id);
        $member = $this->loadMember((int) $memberId, (int) $memorial['id']);

        if ($member['role'] === 'owner') {
            Flash::error('馆主不能被移除。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
        }

        if ((int) $member['user_id'] === (int) Auth::user()['id']) {
            Flash::error('不能移除你自己。如需退出管理，请联系馆主。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
        }

        Member::revoke((int) $member['id']);
        Audit::log('member.revoke', 'memorial_member', (int) $member['id'], [
            'memorial_id' => (int) $memorial['id'],
            'user_id' => (int) $member['user_id'],
            'role' => $member['role'],
        ]);

        Flash::success('该成员已移除，无法再访问纪念馆管理。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
    }

    public function revokeInvitation(Request $request, string $id, string $invitationId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadManageable((int) $id);
        $invitation = Db::fetch(
            'SELECT * FROM invitations WHERE id = ? AND memorial_id = ? LIMIT 1',
            [(int) $invitationId, (int) $memorial['id']]
        );
        if ($invitation === null) {
            throw new HttpException(404, '邀请不存在');
        }

        Invitation::revoke((int) $invitation['id'], (int) $memorial['id']);
        Audit::log('invitation.revoke', 'invitation', (int) $invitation['id'], [
            'memorial_id' => (int) $memorial['id'],
        ]);

        Flash::success('邀请链接已作废。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/members');
    }

    private function renderIndex(array $memorial, array $errors, array $old, int $status = 200): Response
    {
        $invitations = Invitation::pendingFor((int) $memorial['id']);
        foreach ($invitations as &$invitation) {
            $invitation['invite_url'] = $this->baseUrl() . '/invitations/' . $invitation['token'];
        }
        unset($invitation);

        return View::render('manage/members', [
            'title' => '成员与邀请 · ' . $memorial['title'],
            'memorial' => $memorial,
            'members' => Member::all((int) $memorial['id']),
            'invitations' => $invitations,
            'errors' => $errors,
            'old' => $old,
            'noindex' => true,
        ], 'layout', $status);
    }

    private function loadManageable(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        if (!Policy::canManage($memorial, Auth::user())) {
            throw new HttpException(403, '只有馆主可以管理成员');
        }
        return $memorial;
    }

    private function loadMember(int $memberId, int $memorialId): array
    {
        $member = Member::find($memberId);
        if ($member === null || (int) $member['memorial_id'] !== $memorialId || $member['status'] === 'revoked') {
            throw new HttpException(404, '成员不存在');
        }
        return $member;
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
