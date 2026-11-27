<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\FamilyService;

final class FamilyController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        return View::render('family/index', [
            'title' => '我的家族',
            'families' => FamilyService::forUser($user),
            'noindex' => true,
        ]);
    }

    public function create(Request $request): Response
    {
        return View::render('family/create', [
            'title' => '创建家族',
            'errors' => [], 'old' => [], 'noindex' => true,
        ]);
    }

    public function store(Request $request): Response
    {
        Csrf::verify($request);
        $slug = strtolower(trim((string) $request->input('slug', '')));
        if ($slug === '') {
            $slug = 'family-' . substr(bin2hex(random_bytes(5)), 0, 10);
        }
        try {
            $id = FamilyService::create(
                Auth::user(),
                (string) $request->input('name', ''),
                $slug,
                [
                    'surname' => $request->input('surname', ''),
                    'description' => $request->input('description', ''),
                    'origin_place' => $request->input('origin_place', ''),
                    'privacy' => $request->input('privacy', 'private'),
                ]
            );
        } catch (HttpException $e) {
            return View::render('family/create', [
                'title' => '创建家族',
                'errors' => [$e->getMessage()], 'old' => $request->post, 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('家族已创建。创建者默认为家族负责人。');
        return Response::redirect('/families/' . $id);
    }

    /** @param array<string,mixed> $user */
    private function family(Request $request, string $id, string $capability): array
    {
        $familyId = (int) $id;
        $family = FamilyService::find($familyId);
        if ($family === null || !Policy::can($capability, ['family_id' => $familyId, 'family' => $family], Auth::user())) {
            throw new HttpException(404, '家族不存在');
        }
        return $family;
    }

    public function show(Request $request, string $id): Response
    {
        $family = $this->family($request, $id, 'family.view');
        $familyId = (int) $family['id'];
        $user = Auth::user();
        $role = Policy::familyRole($familyId, (int) ($user['id'] ?? 0));
        return View::render('family/show', [
            'title' => (string) $family['name'],
            'family' => $family,
            'role' => $role,
            'canManage' => Policy::can('family.manage', ['family_id' => $familyId, 'family' => $family], $user),
            'counts' => [
                'members' => (int) Db::value("SELECT COUNT(*) FROM family_members WHERE family_id = ? AND status = 'active'", [$familyId]),
                'persons' => (int) Db::value('SELECT COUNT(*) FROM family_persons WHERE family_id = ? AND deleted_at IS NULL', [$familyId]),
                'archives' => 0,
                'events' => 0,
            ],
            'pending' => (int) Db::value("SELECT COUNT(*) FROM person_relationships WHERE family_id = ? AND status = 'pending'", [$familyId]),
            'noindex' => true,
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $family = $this->family($request, $id, 'family.manage');
        return View::render('family/edit', [
            'title' => '编辑家族资料', 'family' => $family, 'errors' => [], 'noindex' => true,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.manage');
        try {
            FamilyService::update(Auth::user(), (int) $family['id'], [
                'name' => $request->input('name', ''),
                'surname' => $request->input('surname', ''),
                'description' => $request->input('description', ''),
                'origin_place' => $request->input('origin_place', ''),
                'privacy' => $request->input('privacy', 'private'),
            ]);
        } catch (HttpException $e) {
            return View::render('family/edit', [
                'title' => '编辑家族资料', 'family' => $family,
                'errors' => [$e->getMessage()], 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('家族资料已保存。');
        return Response::redirect('/families/' . (int) $family['id']);
    }

    public function members(Request $request, string $id): Response
    {
        $family = $this->family($request, $id, 'family.members.manage');
        $familyId = (int) $family['id'];
        return View::render('family/members', [
            'title' => '家族成员',
            'family' => $family,
            'members' => FamilyService::members(Auth::user(), $familyId),
            'invitations' => Db::fetchAll(
                "SELECT id, email, role, status, expires_at FROM family_invitations WHERE family_id = ? AND status = 'pending' ORDER BY id",
                [$familyId]
            ),
            'errors' => [], 'noindex' => true,
        ]);
    }

    public function invite(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.members.manage');
        try {
            $invite = FamilyService::invite(
                Auth::user(), (int) $family['id'],
                (string) $request->input('email', ''), (string) $request->input('role', 'member')
            );
        } catch (HttpException $e) {
            return View::render('family/members', [
                'title' => '家族成员', 'family' => $family,
                'members' => FamilyService::members(Auth::user(), (int) $family['id']),
                'invitations' => [], 'errors' => [$e->getMessage()], 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('邀请已创建，7 天内有效。邀请链接：' . url('/invitations/' . $invite['token']));
        return Response::redirect('/families/' . (int) $family['id'] . '/members');
    }

    public function updateRole(Request $request, string $id, string $userId): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.members.manage');
        FamilyService::updateRole(Auth::user(), (int) $family['id'], (int) $userId, (string) $request->input('role', ''));
        return Response::redirect('/families/' . (int) $family['id'] . '/members');
    }

    public function removeMember(Request $request, string $id, string $userId): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.members.manage');
        FamilyService::removeMember(Auth::user(), (int) $family['id'], (int) $userId);
        return Response::redirect('/families/' . (int) $family['id'] . '/members');
    }

    public function revokeInvitation(Request $request, string $id, string $invitationId): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.members.manage');
        FamilyService::revokeInvitation(Auth::user(), (int) $invitationId);
        return Response::redirect('/families/' . (int) $family['id'] . '/members');
    }

    public function leave(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $family = $this->family($request, $id, 'family.view');
        FamilyService::leave(Auth::user(), (int) $family['id']);
        Flash::success('已退出家族。');
        return Response::redirect('/families');
    }
}
