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
use App\Services\AiWorkflowService;
use App\Services\FamilyService;

/** 2.0 AI 家族记忆工坊 Web 入口；与 API 共用 AiWorkflowService。 */
final class AiWorkshopController
{
    private function family(int $familyId, string $capability): array
    {
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw new HttpException(404, '家族不存在');
        }
        Policy::require($capability, ['family_id' => $familyId, 'family' => $family], Auth::user());
        return $family;
    }

    public function index(Request $request, string $id): Response
    {
        $familyId = (int) $id;
        $family = $this->family($familyId, 'family.view');
        return View::render('ai/index', [
            'title' => 'AI 家族记忆工坊 · 云叙台',
            'noindex' => true,
            'family' => $family,
            'workflows' => AiWorkflowService::forFamily($familyId),
            'facts' => AiWorkflowService::facts($familyId),
            'sessions' => AiWorkflowService::sessions($familyId),
            'logs' => AiWorkflowService::generationLogs($familyId),
            'types' => AiWorkflowService::TYPES,
        ]);
    }

    public function createWorkflow(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'ai.workflow.create');
        $id = AiWorkflowService::create(
            Auth::user(),
            $familyId,
            (string) $request->input('type', ''),
            ['text' => (string) $request->input('text', '')],
            $request->input('idempotency_key') !== null ? (string) $request->input('idempotency_key') : null
        );
        Flash::success('AI 任务已创建，输出将作为草稿等待人工审核。');
        return Response::redirect(url('/families/' . $familyId . '/ai/workflows/' . $id));
    }

    public function showWorkflow(Request $request, string $id, string $jobId): Response
    {
        $familyId = (int) $id;
        $this->family($familyId, 'family.view');
        $workflow = AiWorkflowService::find((int) $jobId);
        if ($workflow === null || (int) $workflow['family_id'] !== $familyId) {
            throw new HttpException(404, '任务不存在');
        }
        return View::render('ai/show', [
            'title' => 'AI 任务详情 · 云叙台',
            'noindex' => true,
            'family' => FamilyService::find($familyId),
            'workflow' => $workflow,
            'steps' => AiWorkflowService::steps((int) $workflow['id']),
        ]);
    }

    /** 手动重跑/执行：与 Worker 共用 process()，便于无 Worker 环境验证。 */
    public function runWorkflow(Request $request, string $id, string $jobId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'ai.workflow.create');
        $id = (int) $jobId;
        $workflow = AiWorkflowService::find($id);
        if ($workflow === null || (int) $workflow['family_id'] !== $familyId) {
            throw new HttpException(404, '任务不存在');
        }
        try {
            AiWorkflowService::process($id, 'web-' . (int) (Auth::user()['id'] ?? 0));
            Flash::success('任务已完成，请审核草稿后再使用。');
        } catch (\Throwable $e) {
            Flash::error('任务执行失败：' . mb_substr($e->getMessage(), 0, 120));
        }
        return Response::redirect(url('/families/' . $familyId . '/ai/workflows/' . $id));
    }

    public function cancelWorkflow(Request $request, string $id, string $jobId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'ai.workflow.create');
        AiWorkflowService::cancel(Auth::user(), (int) $jobId);
        Flash::success('任务已取消。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }

    public function reviewFact(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $factId = (int) $id;
        $fact = Db::fetch('SELECT family_id FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
        if ($fact === null) {
            throw new HttpException(404, '事实记录不存在');
        }
        $familyId = (int) $fact['family_id'];
        $this->family($familyId, 'ai.result.review');
        AiWorkflowService::reviewFact(Auth::user(), $factId, (string) $request->input('status', ''));
        Flash::success('审核结果已保存。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }

    public function createSession(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'ai.workflow.create');
        AiWorkflowService::createOralSession(Auth::user(), $familyId, [
            'title' => (string) $request->input('title', ''),
            'topic' => (string) $request->input('topic', ''),
            'interviewer' => (string) $request->input('interviewer', ''),
            'interviewee_person_id' => (int) $request->input('person_id', 0),
        ]);
        Flash::success('采访记录已创建，录入内容前需先取得受访者授权。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }

    public function consent(Request $request, string $id, string $sessionId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'oral_history.consent.manage');
        $session = AiWorkflowService::session((int) $sessionId);
        if ($session === null || (int) $session['family_id'] !== $familyId) {
            throw new HttpException(404, '采访不存在');
        }
        AiWorkflowService::setConsent(Auth::user(), (int) $session['id'], (string) $request->input('action') === 'grant');
        Flash::success('授权状态已更新。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }

    public function addSegment(Request $request, string $id, string $sessionId): Response
    {
        Csrf::verify($request);
        $familyId = (int) $id;
        $this->family($familyId, 'ai.workflow.create');
        $session = AiWorkflowService::session((int) $sessionId);
        if ($session === null || (int) $session['family_id'] !== $familyId) {
            throw new HttpException(404, '采访不存在');
        }
        AiWorkflowService::addSegment(Auth::user(), (int) $session['id'], [
            'speaker' => (string) $request->input('speaker', ''),
            'start_time' => (int) $request->input('start_time', 0),
            'end_time' => (int) $request->input('end_time', 0),
            'transcript' => (string) $request->input('transcript', ''),
        ]);
        Flash::success('转写片段已保存，等待审核。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }

    public function reviewSegment(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $segment = Db::fetch('SELECT session_id FROM oral_history_segments WHERE id = ? LIMIT 1', [(int) $id]);
        if ($segment === null) {
            throw new HttpException(404, '转写片段不存在');
        }
        $session = AiWorkflowService::session((int) $segment['session_id']);
        if ($session === null) {
            throw new HttpException(404, '采访不存在');
        }
        $familyId = (int) $session['family_id'];
        $this->family($familyId, 'ai.result.review');
        AiWorkflowService::reviewSegment(
            Auth::user(),
            (int) $id,
            (string) $request->input('status') === 'confirmed',
            (string) $request->input('corrected_transcript', '')
        );
        Flash::success('审核结果已保存。');
        return Response::redirect(url('/families/' . $familyId . '/ai'));
    }
}
