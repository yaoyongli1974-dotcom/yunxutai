<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiWorkflowService;
use App\Services\ArchiveService;
use App\Services\ExhibitionService;
use App\Services\FamilyPersonService;
use App\Services\FamilyService;
use App\Services\FamilyTimelineService;
use App\Services\GenealogyService;
use App\Services\NotificationService;

/**
 * 云叙台 2.0 家族业务 API。
 *
 * 与 Web 端共用同一批 Service 和 Policy，不在此文件内重写任何权限判断；
 * HttpException 统一翻译成 API 错误格式，未授权资源一律 404，不暴露存在性。
 */
final class FamilyV2Controller extends BaseController
{
    /** @param callable():mixed $fn */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (HttpException $e) {
            throw match ($e->getStatusCode()) {
                401 => ApiException::unauthorized($e->getMessage()),
                403 => ApiException::forbidden($e->getMessage()),
                404 => ApiException::notFound($e->getMessage()),
                422 => ApiException::validation($e->getMessage()),
                default => new ApiException('request_failed', $e->getMessage(), $e->getStatusCode()),
            };
        }
    }

    private function id(string $value): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw ApiException::validation('编号无效');
        }
        return $id;
    }

    // ---- 家族 ----

    public function families(Request $request): Response
    {
        return Api::ok(['items' => FamilyService::forUser($this->user())]);
    }

    public function createFamily(Request $request): Response
    {
        $user = $this->user();
        $name = Input::string($request, 'name');
        $slug = Input::string($request, 'slug');
        if ($name === '' || strlen($slug) < 2 || strlen($slug) > 60) {
            throw ApiException::validation('家族名称或标识无效', [
                'name' => $name === '' ? '请填写家族名称' : '',
                'slug' => '标识需为 2-60 字符',
            ]);
        }
        $data = [];
        foreach (['surname', 'description', 'origin_place', 'privacy'] as $key) {
            $value = Input::string($request, $key);
            if ($value !== '') {
                $data[$key] = $value;
            }
        }
        $id = $this->call(fn () => FamilyService::create($user, $name, $slug, $data));
        return Api::ok(['id' => $id], 201);
    }

    public function family(Request $request, string $id): Response
    {
        $user = $this->user();
        $familyId = $this->id($id);
        // 公开家族可读，私密/unlisted 家族必须经 Policy 校验；未授权统一 404。
        if (!Policy::canViewResource('family', $familyId, (int) $user['id'])) {
            throw ApiException::notFound('家族不存在');
        }
        $family = FamilyService::find($familyId);
        if ($family === null) {
            throw ApiException::notFound('家族不存在');
        }
        return Api::ok(['family' => $family]);
    }

    public function members(Request $request, string $id): Response
    {
        return Api::ok([
            'items' => $this->call(fn () => FamilyService::members($this->user(), $this->id($id))),
        ]);
    }

    public function invite(Request $request, string $id): Response
    {
        $email = Input::string($request, 'email');
        $role = Input::string($request, 'role', 'member');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ApiException::validation('邮箱格式不正确', ['email' => '请输入有效邮箱']);
        }
        $result = $this->call(fn () => FamilyService::invite($this->user(), $this->id($id), $email, $role));
        return Api::ok($result, 201);
    }

    public function acceptInvitation(Request $request): Response
    {
        if (!\App\Core\RateLimiter::hit(\App\Core\RateLimitKeys::make('invitation_accept', (string) $this->user()['id']), 10, 300)) {
            throw ApiException::rateLimited('邀请接受尝试过于频繁');
        }
        $token = Input::string($request, 'token');
        if ($token === '') {
            throw ApiException::validation('邀请令牌不能为空');
        }
        $familyId = $this->call(fn () => FamilyService::acceptInvitation($this->user(), $token));
        return Api::ok(['family_id' => $familyId]);
    }

    // ---- 人物与谱系 ----

    public function persons(Request $request, string $id): Response
    {
        $user = $this->user();
        $familyId = $this->id($id);
        if (!Policy::canViewResource('family', $familyId, (int) $user['id'])) {
            throw ApiException::notFound('家族不存在');
        }
        $rows = \App\Core\Db::fetchAll(
            'SELECT id, family_id, name, gender, birth_date, death_date, birth_place, privacy,
                    verification_status, is_deceased
             FROM family_persons WHERE family_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 200',
            [$familyId]
        );
        $items = [];
        foreach (FamilyPersonService::visibleRows($user, $rows) as $row) {
            unset($row['is_deceased']); // 仅用于过滤，不改动 API 字段契约
            $items[] = $row;
        }
        return Api::ok(['items' => $items]);
    }

    public function createPerson(Request $request, string $id): Response
    {
        $data = $this->personData($request);
        $id = $this->call(fn () => FamilyPersonService::create($this->user(), $this->id($id), $data));
        return Api::ok(['id' => $id], 201);
    }

    public function person(Request $request, string $personId): Response
    {
        $user = $this->user();
        $person = $this->call(fn () => FamilyPersonService::getVisible($user, $this->id($personId)));
        if ($person === null) {
            throw ApiException::notFound('人物不存在');
        }
        return Api::ok(['person' => $person]);
    }

    /** @return array<string,string> */
    private function personData(Request $request): array
    {
        $data = [];
        foreach (['name', 'gender', 'birth_date', 'death_date', 'birth_place', 'death_place', 'biography', 'privacy'] as $key) {
            $data[$key] = Input::string($request, $key);
        }
        return $data;
    }

    public function genealogy(Request $request, string $id): Response
    {
        $tree = $this->call(fn () => GenealogyService::tree(
            $this->user(),
            $this->id($id),
            Input::int($request, 'center_id'),
            max(1, min(5, Input::int($request, 'depth', 2)))
        ));
        return Api::ok($tree);
    }

    public function submitRelationship(Request $request, string $id): Response
    {
        $newId = $this->call(fn () => GenealogyService::submit(
            $this->user(),
            $this->id($id),
            Input::int($request, 'person_a'),
            Input::int($request, 'person_b'),
            Input::string($request, 'relationship_type'),
            Input::string($request, 'note') ?: null
        ));
        return Api::ok(['id' => $newId, 'status' => 'pending'], 201);
    }

    public function reviewRelationship(Request $request, string $id, string $relationId): Response
    {
        $this->call(fn () => GenealogyService::review(
            $this->user(),
            $this->id($id),
            $this->id($relationId),
            Input::string($request, 'status')
        ));
        return Api::ok(['ok' => true]);
    }

    // ---- 数字档案馆 ----

    public function archives(Request $request, string $id): Response
    {
        return Api::ok(['items' => $this->call(fn () => ArchiveService::listForFamily(
            $this->user(),
            $this->id($id),
            Input::string($request, 'q'),
            max(1, min(200, Input::int($request, 'limit', 100)))
        ))]);
    }

    public function createArchive(Request $request, string $id): Response
    {
        $data = [];
        foreach (['title', 'description', 'archive_type', 'event_date', 'location', 'source_type', 'source_description', 'privacy'] as $key) {
            $value = Input::string($request, $key);
            if ($value !== '') {
                $data[$key] = $value;
            }
        }
        $newId = $this->call(fn () => ArchiveService::create($this->user(), $this->id($id), $data));
        return Api::ok(['id' => $newId], 201);
    }

    public function archive(Request $request, string $archiveId): Response
    {
        $archive = $this->call(fn () => ArchiveService::getVisible($this->user(), $this->id($archiveId)));
        if ($archive === null) {
            throw ApiException::notFound('档案不存在');
        }
        return Api::ok(['archive' => $archive, 'files' => ArchiveService::files((int) $archive['id'])]);
    }

    // ---- AI 工坊 ----

    public function createWorkflow(Request $request, string $id): Response
    {
        if (!\App\Core\RateLimiter::hit(\App\Core\RateLimitKeys::make('ai_workflow_create', (string) $this->user()['id'], $id), 20, 3600)) {
            throw ApiException::rateLimited('AI 任务创建过于频繁，请稍后再试');
        }
        $input = Input::all($request);
        unset($input['type'], $input['idempotency_key']);
        $id = $this->call(fn () => AiWorkflowService::create(
            $this->user(),
            $this->id($id),
            Input::string($request, 'type'),
            $input,
            Input::string($request, 'idempotency_key') ?: null
        ));
        return Api::ok(['id' => $id, 'status' => 'queued'], 201);
    }

    public function workflow(Request $request, string $workflowId): Response
    {
        $wf = $this->call(fn () => AiWorkflowService::getVisible($this->user(), $this->id($workflowId)));
        if ($wf === null) {
            throw ApiException::notFound('AI 任务不存在');
        }
        // F-03：只返回必要字段，不暴露 input_json / provider / 存储路径 / 内部错误。
        $steps = AiWorkflowService::visibleSteps($this->user(), (int) $wf['id']);
        $done = 0;
        foreach ($steps as $step) {
            if (in_array((string) ($step['status'] ?? ''), ['completed', 'approved', 'rejected', 'skipped'], true)) {
                $done++;
            }
        }
        $status = (string) ($wf['status'] ?? '');
        return Api::ok([
            'workflow' => [
                'id' => (int) $wf['id'],
                'workflow_type' => $wf['workflow_type'] ?? null,
                'status' => $wf['status'] ?? null,
                'current_step' => $wf['current_step'] ?? null,
                'created_at' => $wf['created_at'] ?? null,
                'finished_at' => $wf['finished_at'] ?? null,
                'failure_class' => $wf['failure_class'] ?? null,
                // Phase 1：真实状态与可执行动作，前端不再自行推断。
                'progress' => $steps === [] ? 0 : (int) round($done * 100 / count($steps)),
                'step_total' => count($steps),
                'step_done' => $done,
                'can_cancel' => in_array($status, ['queued', 'processing', 'failed'], true),
                'can_retry' => $status === 'failed',
                'deadline_at' => null,
                'last_updated_at' => $wf['updated_at'] ?? null,
                'error_message' => $wf['error_message'] ?? null,
            ],
            'steps' => $steps,
        ]);
    }

    public function cancelWorkflow(Request $request, string $workflowId): Response
    {
        $this->call(fn () => AiWorkflowService::cancel($this->user(), $this->id($workflowId)));
        return Api::ok(['ok' => true]);
    }

    public function reviewFact(Request $request, string $factId): Response
    {
        $this->call(fn () => AiWorkflowService::reviewFact(
            $this->user(),
            $this->id($factId),
            Input::string($request, 'status')
        ));
        return Api::ok(['ok' => true]);
    }

    public function oralSessions(Request $request, string $id): Response
    {
        return Api::ok(['items' => $this->call(fn () => AiWorkflowService::sessions($this->id($id)))]);
    }

    // ---- 时间轴与展馆 ----

    public function events(Request $request, string $id): Response
    {
        return Api::ok(['items' => $this->call(fn () => FamilyTimelineService::events(
            $this->user(),
            $this->id($id),
            Input::string($request, 'type'),
            max(1, min(500, Input::int($request, 'limit', 200)))
        ))]);
    }

    public function createEvent(Request $request, string $id): Response
    {
        $data = [];
        foreach (['title', 'description', 'event_date', 'end_date', 'location', 'event_type', 'privacy', 'source_type'] as $key) {
            $data[$key] = Input::string($request, $key);
        }
        $ids = Input::all($request)['person_ids'] ?? [];
        if (is_array($ids)) {
            $data['person_ids'] = array_values(array_map('intval', $ids));
        }
        $newId = $this->call(fn () => FamilyTimelineService::create($this->user(), $this->id($id), $data));
        return Api::ok(['id' => $newId], 201);
    }

    public function exhibitions(Request $request, string $id): Response
    {
        return Api::ok(['items' => $this->call(fn () => ExhibitionService::listForFamily($this->user(), $this->id($id)))]);
    }

    public function createExhibition(Request $request, string $id): Response
    {
        $data = [];
        foreach (['title', 'description', 'theme', 'cover', 'privacy'] as $key) {
            $data[$key] = Input::string($request, $key);
        }
        $newId = $this->call(fn () => ExhibitionService::create($this->user(), $this->id($id), $data));
        return Api::ok(['id' => $newId, 'status' => 'draft'], 201);
    }

    public function publishExhibition(Request $request, string $exhibitionId): Response
    {
        $this->call(fn () => ExhibitionService::publish($this->user(), $this->id($exhibitionId)));
        return Api::ok(['ok' => true]);
    }

    // ---- 通知 ----

    public function notifications(Request $request): Response
    {
        $user = $this->user();
        $id = (int) $user['id'];
        return Api::ok([
            'items' => NotificationService::forUser($id, Input::bool($request, 'unread_only'), 100),
            'unread_count' => NotificationService::unreadCount($id),
        ]);
    }

    public function readNotification(Request $request, string $notificationId): Response
    {
        NotificationService::markRead((int) $this->user()['id'], $this->id($notificationId));
        return Api::ok(['ok' => true]);
    }

    public function readAllNotifications(Request $request): Response
    {
        return Api::ok(['updated' => NotificationService::markAllRead((int) $this->user()['id'])]);
    }

    public function createOralSession(Request $request, string $id): Response
    {
        $data = [];
        foreach (['title', 'topic', 'interviewer', 'interviewee_person_id', 'audio_file_id'] as $key) {
            $data[$key] = Input::string($request, $key);
        }
        $sessionId = $this->call(fn () => AiWorkflowService::createOralSession($this->user(), $this->id($id), $data));
        return Api::ok(['id' => $sessionId], 201);
    }

    public function oralSession(Request $request, string $sessionId): Response
    {
        $session = $this->call(fn () => AiWorkflowService::session($this->id($sessionId)));
        if ($session === null) {
            throw ApiException::notFound('采访会话不存在');
        }
        return Api::ok(['session' => $session]);
    }

    public function sessionConsent(Request $request, string $sessionId): Response
    {
        $granted = Input::bool($request, 'granted');
        if ($granted) {
            $this->call(fn () => AiWorkflowService::setConsent($this->user(), $this->id($sessionId), true));
        } else {
            $this->call(fn () => AiWorkflowService::revokeSessionConsent($this->user(), $this->id($sessionId)));
        }
        return Api::ok(['ok' => true]);
    }

    public function sessionSegments(Request $request, string $sessionId): Response
    {
        return Api::ok(['items' => $this->call(fn () => AiWorkflowService::segments($this->id($sessionId)))]);
    }

    public function transcribeSession(Request $request, string $sessionId): Response
    {
        $id = $this->call(fn () => AiWorkflowService::transcribeSession($this->user(), $this->id($sessionId)));
        return Api::ok(['id' => $id, 'status' => 'queued'], 201);
    }

    public function addSegment(Request $request, string $sessionId): Response
    {
        $data = [];
        foreach (['speaker', 'start_time', 'end_time', 'transcript'] as $key) {
            $data[$key] = Input::string($request, $key);
        }
        $id = $this->call(fn () => AiWorkflowService::addSegmentChecked($this->user(), $this->id($sessionId), $data));
        return Api::ok(['id' => $id], 201);
    }

    public function correctSegment(Request $request, string $segmentId): Response
    {
        $this->call(fn () => AiWorkflowService::correctSegment(
            $this->user(), $this->id($segmentId), Input::string($request, 'corrected_transcript')
        ));
        return Api::ok(['ok' => true]);
    }

    public function reviewSegment(Request $request, string $segmentId): Response
    {
        $this->call(fn () => AiWorkflowService::reviewSegment(
            $this->user(), $this->id($segmentId), Input::bool($request, 'approve'), Input::string($request, 'corrected_transcript')
        ));
        return Api::ok(['ok' => true]);
    }

    public function exportSession(Request $request, string $sessionId): Response
    {
        return Api::ok($this->call(fn () => AiWorkflowService::exportSessionTranscript($this->user(), $this->id($sessionId))));
    }
}
