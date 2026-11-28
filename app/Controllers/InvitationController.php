<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Invitation;
use App\Models\Member;
use App\Models\Memorial;
use App\Services\FamilyService;

final class InvitationController
{
    /** 家族邀请令牌与纪念馆邀请令牌互不冲突：先按 sha256 查家族邀请。 */
    private function familyInvitation(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return Db::fetch(
            'SELECT i.*, f.name AS family_name FROM family_invitations i
             JOIN families f ON f.id = i.family_id
             WHERE i.token_hash = ? AND i.status = ? LIMIT 1',
            [hash('sha256', $token), 'pending']
        );
    }

    public function show(Request $request, string $token): Response
    {
        $familyInvite = $this->familyInvitation($token);
        if ($familyInvite !== null) {
            $user = Auth::user();
            return View::render('family/invitation', [
                'title' => '家族邀请',
                'invitation' => $familyInvite,
                'token' => $token,
                'emailMatches' => strtolower((string) ($user['email'] ?? '')) === strtolower((string) $familyInvite['email']),
                'noindex' => true,
            ]);
        }

        $invitation = Invitation::findOpenByToken($token);
        if ($invitation === null) {
            return View::render('invitations/invalid', ['title' => '邀请已失效'], 'layout', 404);
        }

        $user = Auth::user();
        $existing = Member::roleOf((int) $invitation['memorial_id'], (int) $user['id']);

        return View::render('invitations/show', [
            'title' => '纪念馆邀请',
            'invitation' => $invitation,
            'existingRole' => $existing,
            'noindex' => true,
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        Csrf::verify($request);
        if (!\App\Core\RateLimiter::hit(\App\Core\RateLimitKeys::make('invitation_accept', (string) (Auth::user()['id'] ?? ''), $request->ip()), 10, 300)) {
            throw new HttpException(429, '邀请接受尝试过于频繁');
        }

        $familyInvite = $this->familyInvitation($token);
        if ($familyInvite !== null) {
            $familyId = FamilyService::acceptInvitation(Auth::user(), $token);
            Flash::success('已加入家族：' . (string) $familyInvite['family_name'] . '。');
            return Response::redirect('/families/' . $familyId);
        }

        $invitation = Invitation::findOpenByToken($token);
        if ($invitation === null) {
            throw new HttpException(404, '邀请已失效');
        }

        $user = Auth::user();
        // 纪念馆邀请同样必须是受邀邮箱本人：与家族邀请保持一致，防止令牌被转手使用。
        $inviteEmail = strtolower(trim((string) ($invitation['email'] ?? '')));
        if ($inviteEmail === '' || strtolower(trim((string) ($user['email'] ?? ''))) !== $inviteEmail) {
            throw new HttpException(404, '邀请不存在或已失效');
        }

        Member::upsert((int) $invitation['memorial_id'], (int) $user['id'], (string) $invitation['role'], (int) $invitation['created_by']);
        Invitation::accept((int) $invitation['id'], (int) $user['id']);
        Policy::clearCache();

        Audit::log('invitation.accept', 'memorial', (int) $invitation['memorial_id'], [
            'invitation_id' => (int) $invitation['id'],
            'role' => $invitation['role'],
        ]);

        Flash::success('已加入纪念馆，角色：' . role_label((string) $invitation['role']) . '。');
        return Response::redirect('/manage/memorials/' . (int) $invitation['memorial_id']);
    }
}
