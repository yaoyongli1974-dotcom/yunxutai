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
use App\Services\ExhibitionService;
use App\Services\FamilyService;

final class ExhibitionController
{
    public function index(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('family.view', ['family_id' => $familyId, 'family' => $family], Auth::user());
        return View::render('exhibition/index', [
            'title' => '数字展馆 · 云叙台',
            'noindex' => true,
            'family' => $family,
            'exhibitions' => ExhibitionService::listForFamily(Auth::user(), $familyId),
            'canEdit' => Policy::can('exhibition.edit', ['family_id' => $familyId, 'family' => $family], Auth::user()),
        ]);
    }

    public function store(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('exhibition.edit', ['family_id' => $familyId, 'family' => $family], Auth::user());
        $exhibitionId = ExhibitionService::create(Auth::user(), $familyId, [
            'title' => (string) $request->input('title', ''),
            'description' => (string) $request->input('description', ''),
            'theme' => (string) $request->input('theme', ''),
            'privacy' => (string) $request->input('privacy', 'family'),
        ]);
        Flash::success('展馆草稿已创建。');
        return Response::redirect(url('/exhibitions/' . $exhibitionId));
    }

    public function show(Request $request, string $id): Response
    {
        $exhibition = ExhibitionService::visible(Auth::user(), (int) $id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $family = FamilyService::find((int) $exhibition['family_id']);
        return View::render('exhibition/show', [
            'title' => (string) $exhibition['title'] . ' · 云叙台',
            'noindex' => true,
            'family' => $family,
            'exhibition' => $exhibition,
            'items' => ExhibitionService::items((int) $exhibition['id']),
            'canEdit' => Policy::can('exhibition.edit', ['family_id' => (int) $exhibition['family_id'], 'family' => $family], Auth::user()),
            'canPublish' => Policy::can('exhibition.publish', ['family_id' => (int) $exhibition['family_id'], 'family' => $family], Auth::user()),
            'archives' => \App\Services\ArchiveService::listForFamily(Auth::user(), (int) $exhibition['family_id']),
        ]);
    }

    public function addItem(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $exhibition = ExhibitionService::find((int) $id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $familyId = (int) $exhibition['family_id'];
        $family = FamilyService::find($familyId);
        Policy::require('exhibition.edit', ['family_id' => $familyId, 'family' => $family], Auth::user());
        ExhibitionService::addItem(Auth::user(), (int) $id, (string) $request->input('item_type', ''),
            (int) $request->input('item_id', 0), ['section' => (string) $request->input('section', 'default')]);
        Flash::success('内容已加入展馆。');
        return Response::redirect(url('/exhibitions/' . $id));
    }

    public function publish(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $exhibition = ExhibitionService::find((int) $id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $family = FamilyService::find((int) $exhibition['family_id']);
        Policy::require('exhibition.publish', ['family_id' => (int) $exhibition['family_id'], 'family' => $family], Auth::user());
        try {
            ExhibitionService::publish(Auth::user(), (int) $id);
            Flash::success('展馆已发布。');
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect(url('/exhibitions/' . $id));
    }

    public function offline(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $exhibition = ExhibitionService::find((int) $id);
        if ($exhibition === null) {
            throw new HttpException(404, '展馆不存在');
        }
        $family = FamilyService::find((int) $exhibition['family_id']);
        Policy::require('exhibition.publish', ['family_id' => (int) $exhibition['family_id'], 'family' => $family], Auth::user());
        ExhibitionService::offline(Auth::user(), (int) $id);
        Flash::success('展馆已下线。');
        return Response::redirect(url('/exhibitions/' . $id));
    }
}
