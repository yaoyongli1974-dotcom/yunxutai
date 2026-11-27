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
use App\Services\FamilyPersonService;
use App\Services\FamilyService;
use App\Services\GenealogyService;

final class FamilyPersonController
{
    private function family(Request $request, int $familyId, string $capability): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null || !Policy::can($capability, ['family_id' => $familyId, 'family' => $family], Auth::user())) {
            throw new HttpException(404, '家族不存在');
        }
        return $family;
    }

    public function index(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'family.view');
        $rows = Db::fetchAll(
            'SELECT * FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY id',
            [$familyId]
        );
        $persons = [];
        foreach ($rows as $row) {
            $visible = FamilyPersonService::getVisible(Auth::user(), (int) $row['id']);
            if ($visible !== null) {
                $persons[] = $visible;
            }
        }
        return View::render('family/persons/index', [
            'title' => '家族人物', 'family' => $family, 'persons' => $persons,
            'canEdit' => Policy::can('person.edit', ['family_id' => $familyId, 'family' => $family], Auth::user()),
            'duplicates' => Policy::can('person.merge', ['family_id' => $familyId], Auth::user())
                ? GenealogyService::duplicates($familyId) : [],
            'noindex' => true,
        ]);
    }

    public function create(Request $request, string $id): Response
    {
        $family = $this->family($request, (int) $id, 'person.edit');
        return View::render('family/persons/create', [
            'title' => '新增人物', 'family' => $family, 'errors' => [], 'old' => [],
            'noindex' => true,
        ]);
    }

    public function store(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'person.edit');
        try {
            $personId = FamilyPersonService::create(Auth::user(), $familyId, $request->post);
        } catch (HttpException $e) {
            return View::render('family/persons/create', [
                'title' => '新增人物', 'family' => $family,
                'errors' => [$e->getMessage()], 'old' => $request->post, 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('人物已创建；新建资料默认为待核验状态，不会自动成为公开事实。');
        return Response::redirect('/families/' . $familyId . '/persons/' . $personId);
    }

    public function show(Request $request, string $id, string $personId): Response
    {
        $person = FamilyPersonService::getVisible(Auth::user(), (int) $personId);
        if ($person === null || (int) $person['family_id'] !== (int) $id) {
            throw new HttpException(404, '人物不存在');
        }
        $family = $this->family($request, (int) $id, 'family.view');
        return View::render('family/persons/show', [
            'title' => (string) $person['name'],
            'family' => $family,
            'person' => $person,
            'canEdit' => Policy::can('person.edit', ['family_id' => (int) $id, 'family' => $family], Auth::user()),
            'links' => Db::fetchAll(
                'SELECT l.*, m.title AS memorial_title, m.slug AS memorial_slug
                 FROM person_memorial_links l JOIN memorials m ON m.id = l.memorial_id
                 WHERE l.person_id = ? AND l.status = ? ORDER BY l.id',
                [(int) $personId, 'active']
            ),
            'memorials' => Db::fetchAll('SELECT id, title FROM memorials WHERE status = ? ORDER BY id LIMIT 200', ['active']),
            'branches' => GenealogyService::branches(Auth::user(), (int) $id),
            'assigned' => array_column(
                Db::fetchAll('SELECT branch_id FROM person_branch_members WHERE person_id = ?', [(int) $personId]),
                'branch_id'
            ),
            'noindex' => true,
        ]);
    }

    public function edit(Request $request, string $id, string $personId): Response
    {
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'person.edit');
        $person = Db::fetch(
            'SELECT * FROM family_persons WHERE id = ? AND family_id = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $personId, $familyId]
        );
        if ($person === null) {
            throw new HttpException(404, '人物不存在');
        }
        return View::render('family/persons/edit', [
            'title' => '编辑人物', 'family' => $family, 'person' => $person,
            'errors' => [], 'noindex' => true,
        ]);
    }

    public function update(Request $request, string $id, string $personId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'person.edit');
        try {
            FamilyPersonService::update(Auth::user(), $familyId, (int) $personId, $request->post);
        } catch (HttpException $e) {
            $person = Db::fetch('SELECT * FROM family_persons WHERE id = ? LIMIT 1', [(int) $personId]) ?? [];
            return View::render('family/persons/edit', [
                'title' => '编辑人物', 'family' => $family, 'person' => $person,
                'errors' => [$e->getMessage()], 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('人物资料已保存。');
        return Response::redirect('/families/' . $familyId . '/persons/' . (int) $personId);
    }

    public function linkMemorial(Request $request, string $id, string $personId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'person.edit');
        FamilyPersonService::linkMemorial(
            Auth::user(), $familyId, (int) $personId, (int) $request->input('memorial_id', 0),
            (string) $request->input('note', '')
        );
        Flash::success('纪念馆已关联至该人物。');
        return Response::redirect('/families/' . $familyId . '/persons/' . (int) $personId);
    }

    public function mergeForm(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'person.merge');
        return View::render('family/persons/merge', [
            'title' => '合并重复人物', 'family' => $family,
            'persons' => Db::fetchAll(
                'SELECT id, name, birth_date FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY name, id',
                [$familyId]
            ),
            'errors' => [], 'noindex' => true,
        ]);
    }

    public function merge(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $family = $this->family($request, $familyId, 'person.merge');
        try {
            GenealogyService::mergePersons(
                Auth::user(), $familyId,
                (int) $request->input('primary_id', 0), (int) $request->input('duplicate_id', 0)
            );
        } catch (HttpException $e) {
            return View::render('family/persons/merge', [
                'title' => '合并重复人物', 'family' => $family,
                'persons' => Db::fetchAll('SELECT id, name, birth_date FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY name, id', [$familyId]),
                'errors' => [$e->getMessage()], 'noindex' => true,
            ], 'layout', $e->getStatusCode());
        }
        Flash::success('重复人物已合并；原记录保留可追溯标记，关联资料已迁移。');
        return Response::redirect('/families/' . $familyId . '/persons');
    }
}
