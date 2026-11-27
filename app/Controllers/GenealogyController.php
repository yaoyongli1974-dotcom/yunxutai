<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\FamilyService;
use App\Services\GenealogyService;

final class GenealogyController
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
        $tree = GenealogyService::tree(Auth::user(), $familyId, $request->int('center'), $request->int('depth', 2));
        return View::render('family/genealogy', [
            'title' => '家族谱系',
            'family' => $family,
            'tree' => $tree,
            'relations' => GenealogyService::relations(Auth::user(), $familyId),
            'branches' => GenealogyService::branches(Auth::user(), $familyId),
            'persons' => $tree['persons'],
            'relationTypes' => GenealogyService::RELATION_TYPES,
            'canEdit' => Policy::can('person.edit', ['family_id' => $familyId, 'family' => $family], Auth::user()),
            'canReview' => Policy::can('relationship.review', ['family_id' => $familyId, 'family' => $family], Auth::user()),
            'errors' => [], 'noindex' => true,
        ]);
    }

    public function submit(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'relationship.submit');
        try {
            GenealogyService::submit(
                Auth::user(), $familyId,
                (int) $request->input('person_a_id', 0), (int) $request->input('person_b_id', 0),
                (string) $request->input('relationship_type', ''), (string) $request->input('note', '')
            );
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
            return Response::redirect('/families/' . $familyId . '/genealogy');
        }
        Flash::success('关系已提交，待家族管理员审核后进入正式谱系。');
        return Response::redirect('/families/' . $familyId . '/genealogy');
    }

    public function review(Request $request, string $id, string $relationId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'relationship.review');
        try {
            GenealogyService::review(Auth::user(), $familyId, (int) $relationId, (string) $request->input('status', ''));
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/families/' . $familyId . '/genealogy');
    }

    public function revoke(Request $request, string $id, string $relationId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'family.view');
        try {
            GenealogyService::revoke(Auth::user(), $familyId, (int) $relationId);
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/families/' . $familyId . '/genealogy');
    }

    public function createBranch(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'person.edit');
        try {
            GenealogyService::createBranch(
                Auth::user(), $familyId, (string) $request->input('name', ''),
                (int) $request->input('parent_id', 0) ?: null
            );
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/families/' . $familyId . '/genealogy');
    }

    public function assignBranch(Request $request, string $id, string $personId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($request, $familyId, 'person.edit');
        try {
            GenealogyService::assignBranch(
                Auth::user(), $familyId, (int) $personId, (int) $request->input('branch_id', 0)
            );
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/families/' . $familyId . '/persons/' . (int) $personId);
    }
}
