<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
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

final class InvitationController
{
    public function show(Request $request, string $token): Response
    {
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

        $invitation = Invitation::findOpenByToken($token);
        if ($invitation === null) {
            throw new HttpException(404, '邀请已失效');
        }

        $user = Auth::user();
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
