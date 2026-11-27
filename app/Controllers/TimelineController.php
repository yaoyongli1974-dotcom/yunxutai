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
use App\Services\FamilyTimelineService;

final class TimelineController
{
    public function index(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require('family.view', ['family_id' => $familyId, 'family' => $family], Auth::user());
        $events = FamilyTimelineService::events(Auth::user(), $familyId, (string) ($request->input('type') ?? ''));
        $withPersons = [];
        foreach ($events as $event) {
            $withPersons[$event['id']] = FamilyTimelineService::persons((int) $event['id']);
        }
        return View::render('timeline/index', [
            'title' => '家族时间轴 · 云叙台',
            'noindex' => true,
            'family' => $family,
            'events' => $events,
            'withPersons' => $withPersons,
            'canEdit' => Policy::can('timeline.edit', ['family_id' => $familyId, 'family' => $family], Auth::user()),
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
        Policy::require('timeline.edit', ['family_id' => $familyId, 'family' => $family], Auth::user());
        FamilyTimelineService::create(Auth::user(), $familyId, [
            'title' => (string) $request->input('title', ''),
            'description' => (string) $request->input('description', ''),
            'event_date' => (string) $request->input('event_date', ''),
            'end_date' => (string) $request->input('end_date', ''),
            'location' => (string) $request->input('location', ''),
            'event_type' => (string) $request->input('event_type', 'other'),
            'privacy' => (string) $request->input('privacy', 'family'),
            'source_type' => (string) $request->input('source_type', 'user_input'),
            'person_ids' => is_array($request->input('person_ids')) ? $request->input('person_ids') : [],
        ]);
        Flash::success('家族事件已保存。');
        return Response::redirect(url('/families/' . $familyId . '/timeline'));
    }

    public function archive(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $event = FamilyTimelineService::event(Auth::user(), (int) $id);
        if ($event === null) {
            throw new HttpException(404, '事件不存在');
        }
        $familyId = (int) $event['family_id'];
        FamilyTimelineService::archive(Auth::user(), (int) $id);
        Flash::success('事件已归档。');
        return Response::redirect(url('/families/' . $familyId . '/timeline'));
    }
}
