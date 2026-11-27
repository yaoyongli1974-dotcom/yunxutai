<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;
use App\Services\Ai\AiClientFactory;
use RuntimeException;
use Throwable;

/**
 * 2.0 AI 家族记忆工作流：与旧 ai_jobs / AiStudioService 完全分离。
 * 所有生成内容均为草稿或候选事实，必须人工审核后才可成为正式事实。
 */
final class AiWorkflowService
{
    public const TYPES = [
        'ocr_document' => ['文档 OCR', ['validate_input', 'recognize', 'review_draft']],
        'transcribe_audio' => ['语音转写', ['validate_input', 'transcribe', 'segment', 'review_draft']],
        'extract_facts' => ['人物与事件提取', ['validate_input', 'extract_candidates', 'review_draft']],
        'biography_draft' => ['人物传记草稿', ['validate_input', 'compose', 'review_draft']],
        'family_history_draft' => ['家族史草稿', ['validate_input', 'compose', 'review_draft']],
        'oral_assist' => ['口述历史追问', ['validate_consent', 'compose', 'review_draft']],
    ];

    public const PROMPT_VERSION = 'v1';

    /** 创建任务；幂等键在同一家族同一类型内唯一。 */
    public static function create(?array $user, int $familyId, string $type, array $input, ?string $idempotencyKey = null): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new HttpException(422, '不支持的 AI 工作流类型');
        }
        Policy::require('ai.workflow.create', ['family_id' => $familyId, 'resource' => null], $user);
        self::assertInputAuthorized($user, $familyId, $type, $input);

        $key = $idempotencyKey !== null && $idempotencyKey !== ''
            ? hash('sha256', $familyId . '|' . $type . '|' . $idempotencyKey)
            : bin2hex(random_bytes(32));
        $existing = Db::value(
            'SELECT id FROM ai_workflows WHERE family_id = ? AND workflow_type = ? AND idempotency_key = ? LIMIT 1',
            [$familyId, $type, $key]
        );
        if ($existing !== null) {
            return (int) $existing;
        }

        $id = Db::transaction(static function () use ($familyId, $type, $input, $key, $user): int {
            $newId = Db::insert('ai_workflows', [
                'family_id' => $familyId,
                'workflow_type' => $type,
                'status' => 'queued',
                'idempotency_key' => $key,
                'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
                'provider' => AiClientFactory::resolve('text')['provider'],
                'prompt_version' => self::PROMPT_VERSION,
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            foreach (self::TYPES[$type][1] as $index => $step) {
                Db::insert('ai_workflow_steps', [
                    'workflow_id' => $newId,
                    'step_no' => $index + 1,
                    'name' => $step,
                ]);
            }
            return $newId;
        });

        Audit::log('ai.workflow.created', 'ai_workflow', $id, [
            'family_id' => $familyId, 'type' => $type,
        ], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
    }

    public static function forFamily(int $familyId, int $limit = 50): array
    {
        return Db::fetchAll(
            'SELECT id, workflow_type, status, current_step, failure_class, retry_count, created_at, finished_at
             FROM ai_workflows WHERE family_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$familyId]
        );
    }

    public static function steps(int $id): array
    {
        return Db::fetchAll('SELECT * FROM ai_workflow_steps WHERE workflow_id = ? ORDER BY step_no', [$id]);
    }

    /** Worker 原子领取：只有满足状态与时间条件的更新成功才算拿到任务。 */
    public static function claim(string $workerId, int $limit = 5): array
    {
        $rows = Db::fetchAll(
            'SELECT id, workflow_type FROM ai_workflows
             WHERE status IN (\'queued\', \'failed\') AND run_after <= NOW() AND retry_count < max_retries
             ORDER BY id ASC LIMIT ' . max(1, min(50, $limit))
        );
        $claimed = [];
        foreach ($rows as $row) {
            $steps = Db::fetchAll(
                'SELECT name FROM ai_workflow_steps WHERE workflow_id = ? AND status IN (\'queued\',\'failed\') ORDER BY step_no LIMIT 1',
                [(int) $row['id']]
            );
            $step = $steps[0]['name'] ?? 'validate_input';
            $ok = Db::update(
                'ai_workflows',
                [
                    'status' => 'processing',
                    'locked_by' => $workerId,
                    'lease_until' => date('Y-m-d H:i:s', time() + 900),
                    'current_step' => $step,
                    'started_at' => date('Y-m-d H:i:s'),
                ],
                'id = ? AND status IN (\'queued\',\'failed\') AND run_after <= NOW() AND retry_count < max_retries',
                [(int) $row['id']]
            );
            if ($ok === 1) {
                $claimed[] = (int) $row['id'];
                Audit::log('ai.workflow.claimed', 'ai_workflow', (int) $row['id'], ['worker' => $workerId]);
            }
        }
        return $claimed;
    }

    /** Worker 异常退出后的租约恢复 + 指数退避重排队。 */
    public static function recoverExpired(): array
    {
        $expired = Db::fetchAll(
            'SELECT id FROM ai_workflows WHERE status = \'processing\' AND lease_until IS NOT NULL AND lease_until < NOW()'
        );
        foreach ($expired as $row) {
            Db::update('ai_workflows', [
                'status' => 'failed',
                'failure_class' => 'lease_expired',
                'error_message' => 'Worker 租约超时，任务已回收',
                'locked_by' => null,
                'lease_until' => null,
            ], 'id = ? AND status = \'processing\'', [(int) $row['id']]);
            Audit::log('ai.workflow.lease_expired', 'ai_workflow', (int) $row['id'], []);
        }
        $retried = 0;
        $failed = Db::fetchAll(
            'SELECT id, retry_count FROM ai_workflows WHERE status = \'failed\' AND retry_count < max_retries AND run_after <= NOW()'
        );
        foreach ($failed as $row) {
            $delay = min(60, 2 ** (int) $row['retry_count']);
            $ok = Db::update(
                'ai_workflows',
                ['status' => 'queued', 'run_after' => date('Y-m-d H:i:s', time() + $delay)],
                'id = ? AND status = \'failed\' AND retry_count < max_retries',
                [(int) $row['id']]
            );
            $retried += $ok;
        }
        return ['expired' => count($expired), 'requeued' => $retried];
    }

    /** 执行任务；online handler 走 AI Provider，未配置时用本地草稿模板，绝不编造为事实。 */
    public static function process(int $id, string $workerId = 'inline'): array
    {
        $workflow = self::find($id);
        if ($workflow === null) {
            throw new RuntimeException('AI 工作流不存在');
        }
        if (!in_array($workflow['status'], ['processing', 'queued', 'failed'], true)) {
            throw new RuntimeException('该任务当前不可执行');
        }
        if ($workflow['status'] !== 'processing') {
            self::claim($workerId, 1000);
            $workflow = self::find($id) ?? $workflow;
            if ($workflow['status'] !== 'processing' || $workflow['locked_by'] !== $workerId) {
                throw new RuntimeException('任务已被其他 Worker 领取');
            }
        }

        $type = (string) $workflow['workflow_type'];
        $input = json_decode((string) $workflow['input_json'], true) ?: [];
        $familyId = (int) $workflow['family_id'];
        try {
            $output = self::run($type, $input);
            Db::transaction(static function () use ($id, $output, $familyId, $workflow, $input): void {
                $steps = Db::fetchAll('SELECT id, name FROM ai_workflow_steps WHERE workflow_id = ? ORDER BY step_no', [$id]);
                foreach ($steps as $step) {
                    Db::update('ai_workflow_steps', [
                        'status' => 'completed',
                        'input_ref' => json_encode(['workflow_id' => $id, 'family_id' => $familyId], JSON_UNESCAPED_UNICODE),
                        'output_ref' => (string) $step['name'] === 'review_draft' ? 'pending_review' : 'output_json',
                        'finished_at' => date('Y-m-d H:i:s'),
                    ], 'id = ?', [(int) $step['id']]);
                }
                Db::update('ai_workflows', [
                    'status' => 'completed',
                    'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE),
                    'current_step' => null,
                    'error_message' => null,
                    'failure_class' => null,
                    'lease_until' => null,
                    'locked_by' => null,
                    'finished_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
                Db::insert('ai_generation_logs', [
                    'workflow_id' => $id,
                    'family_id' => $familyId,
                    'provider' => (string) $workflow['provider'],
                    'model' => (string) (AiClientFactory::resolve('text')['model'] ?? ''),
                    'prompt_version' => self::PROMPT_VERSION,
                    'input_refs' => json_encode(['input_keys' => array_keys($input)], JSON_UNESCAPED_UNICODE),
                    'created_by' => (int) $workflow['created_by'],
                ]);
            });
            Audit::log('ai.workflow.completed', 'ai_workflow', $id, ['family_id' => $familyId, 'type' => $type]);
            return self::find($id) ?? $workflow;
        } catch (Throwable $e) {
            $retry = (int) $workflow['retry_count'] + 1;
            $max = (int) $workflow['max_retries'];
            $class = $e instanceof HttpException ? 'input_error' : 'provider_error';
            if ($class === 'input_error') {
                $retry = $max; // 参数/授权错误不重试
            }
            Db::update('ai_workflow_steps', [
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 200),
                'finished_at' => date('Y-m-d H:i:s'),
            ], 'workflow_id = ? AND status = \'processing\'', [$id]);
            $status = $retry < $max ? 'queued' : 'failed';
            $delay = min(60, 2 ** max(0, $retry - 1));
            Db::update('ai_workflows', [
                'status' => $status,
                'retry_count' => $retry,
                'failure_class' => $class,
                'error_message' => mb_substr($e->getMessage(), 0, 200),
                'lease_until' => null,
                'locked_by' => null,
                'run_after' => date('Y-m-d H:i:s', time() + ($status === 'queued' ? $delay : 0)),
            ], 'id = ?', [$id]);
            Audit::log('ai.workflow.failed', 'ai_workflow', $id, [
                'class' => $class, 'retry' => $retry, 'error' => mb_substr($e->getMessage(), 0, 120),
            ]);
            throw $e;
        }
    }

    public static function cancel(?array $user, int $id): void
    {
        $workflow = self::find($id);
        if ($workflow === null) {
            throw new HttpException(404, '任务不存在');
        }
        $familyId = (int) $workflow['family_id'];
        Policy::require('ai.workflow.create', ['family_id' => $familyId, 'resource' => null], $user);
        $role = Policy::familyRole($familyId, (int) ($user['id'] ?? 0));
        if (!in_array($role, ['owner', 'admin'], true) && (int) $workflow['created_by'] !== (int) ($user['id'] ?? 0)) {
            throw new HttpException(404, '任务不存在');
        }
        if (!in_array($workflow['status'], ['queued', 'failed', 'processing'], true)) {
            throw new HttpException(422, '该任务已结束，无法取消');
        }
        Db::update('ai_workflows', [
            'status' => 'cancelled', 'lease_until' => null, 'locked_by' => null,
            'finished_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
        Db::update('ai_workflow_steps', ['status' => 'skipped'], 'workflow_id = ? AND status IN (\'queued\',\'failed\')', [$id]);
        Audit::log('ai.workflow.cancelled', 'ai_workflow', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
    }

    /** AI 生成的事实一律 pending；确认只能通过人工审核接口。 */
    public static function addFactCandidate(
        ?array $user,
        int $familyId,
        string $factType,
        string $content,
        string $sourceType,
        ?int $sourceId = null,
        ?int $personId = null,
        float $confidence = 0.5
    ): int {
        if (!in_array($sourceType, ['user_input', 'oral_history', 'archive_ocr', 'ai_extraction', 'family_confirmation', 'imported'], true)) {
            throw new HttpException(422, '未知的事实来源类型');
        }
        $id = Db::insert('memory_facts', [
            'family_id' => $familyId,
            'person_id' => $personId,
            'fact_type' => mb_substr($factType, 0, 40),
            'fact_content' => mb_substr($content, 0, 2000),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'confidence' => max(0, min(0.999, $confidence)),
            'verification_status' => 'pending',
            'submitted_by' => (int) ($user['id'] ?? 0),
        ]);
        Audit::log('ai.fact.candidate', 'memory_fact', $id, ['family_id' => $familyId, 'source' => $sourceType], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function facts(int $familyId, string $status = ''): array
    {
        $sql = 'SELECT * FROM memory_facts WHERE family_id = ?';
        $params = [$familyId];
        if ($status !== '') {
            $sql .= ' AND verification_status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        return Db::fetchAll($sql, $params);
    }

    public static function reviewFact(?array $user, int $factId, string $status): void
    {
        if (!in_array($status, ['confirmed', 'disputed', 'rejected', 'pending'], true)) {
            throw new HttpException(422, '无效的审核状态');
        }
        $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
        if ($fact === null) {
            throw new HttpException(404, '事实记录不存在');
        }
        Policy::require('ai.result.review', ['family_id' => (int) $fact['family_id'], 'resource' => null], $user);
        Db::update('memory_facts', [
            'verification_status' => $status,
            'reviewed_by' => (int) ($user['id'] ?? 0),
            'reviewed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$factId]);
        Audit::log('ai.fact.reviewed', 'memory_fact', $factId, [
            'family_id' => (int) $fact['family_id'], 'status' => $status,
        ], (int) ($user['id'] ?? 0));
    }

    public static function createOralSession(?array $user, int $familyId, array $data): int
    {
        Policy::require('ai.workflow.create', ['family_id' => $familyId, 'resource' => null], $user);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new HttpException(422, '请填写采访标题');
        }
        $id = Db::insert('oral_history_sessions', [
            'family_id' => $familyId,
            'title' => mb_substr($title, 0, 180),
            'topic' => mb_substr(trim((string) ($data['topic'] ?? '')), 0, 120),
            'interviewer' => mb_substr(trim((string) ($data['interviewer'] ?? '')), 0, 120),
            'interviewee_person_id' => (int) ($data['interviewee_person_id'] ?? 0) ?: null,
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        Audit::log('oral.session.created', 'oral_history_session', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function setConsent(?array $user, int $sessionId, bool $granted): void
    {
        $session = Db::fetch('SELECT * FROM oral_history_sessions WHERE id = ? LIMIT 1', [$sessionId]);
        if ($session === null) {
            throw new HttpException(404, '采访不存在');
        }
        Policy::require('oral_history.consent.manage', ['family_id' => (int) $session['family_id'], 'resource' => null], $user);
        Db::update('oral_history_sessions', [
            'consent_status' => $granted ? 'granted' : 'revoked',
            'consent_at' => date('Y-m-d H:i:s'),
            'consent_by' => (int) ($user['id'] ?? 0),
        ], 'id = ?', [$sessionId]);
        Audit::log($granted ? 'oral.consent.granted' : 'oral.consent.revoked', 'oral_history_session', $sessionId, [
            'family_id' => (int) $session['family_id'],
        ], (int) ($user['id'] ?? 0));
    }

    public static function sessions(int $familyId): array
    {
        return Db::fetchAll(
            'SELECT id, title, topic, consent_status, processing_status, created_at
             FROM oral_history_sessions WHERE family_id = ? ORDER BY id DESC LIMIT 100',
            [$familyId]
        );
    }

    public static function session(int $id): ?array
    {
        return Db::fetch('SELECT * FROM oral_history_sessions WHERE id = ? LIMIT 1', [$id]);
    }

    public static function addSegment(?array $user, int $sessionId, array $data): int
    {
        $session = self::session($sessionId);
        if ($session === null) {
            throw new HttpException(404, '采访不存在');
        }
        Policy::require('ai.workflow.create', ['family_id' => (int) $session['family_id'], 'resource' => null], $user);
        if (($session['consent_status'] ?? '') !== 'granted') {
            throw new HttpException(422, '未获得受访者授权，不能录入转写内容');
        }
        $id = Db::insert('oral_history_segments', [
            'session_id' => $sessionId,
            'speaker' => mb_substr(trim((string) ($data['speaker'] ?? '')), 0, 120),
            'start_time' => max(0, (int) ($data['start_time'] ?? 0)),
            'end_time' => max(0, (int) ($data['end_time'] ?? 0)),
            'transcript' => mb_substr(trim((string) ($data['transcript'] ?? '')), 0, 5000),
            'review_status' => 'pending',
        ]);
        Audit::log('oral.segment.created', 'oral_history_segment', $id, ['session_id' => $sessionId], (int) ($user['id'] ?? 0));
        return $id;
    }

    public static function reviewSegment(?array $user, int $segmentId, bool $approve, string $corrected = ''): void
    {
        $segment = Db::fetch('SELECT * FROM oral_history_segments WHERE id = ? LIMIT 1', [$segmentId]);
        if ($segment === null) {
            throw new HttpException(404, '转写片段不存在');
        }
        $session = self::session((int) $segment['session_id']);
        if ($session === null || ($session['consent_status'] ?? '') !== 'granted') {
            throw new HttpException(422, '授权已撤回，不能审核该内容');
        }
        Policy::require('ai.result.review', ['family_id' => (int) $session['family_id'], 'resource' => null], $user);
        Db::update('oral_history_segments', [
            'review_status' => $approve ? 'confirmed' : 'rejected',
            'corrected_transcript' => mb_substr($corrected, 0, 5000),
            'reviewed_by' => (int) ($user['id'] ?? 0),
        ], 'id = ?', [$segmentId]);
        Audit::log('oral.segment.reviewed', 'oral_history_segment', $segmentId, [
            'approve' => $approve,
        ], (int) ($user['id'] ?? 0));
    }

    public static function segments(int $sessionId): array
    {
        return Db::fetchAll('SELECT * FROM oral_history_segments WHERE session_id = ? ORDER BY start_time, id', [$sessionId]);
    }

    public static function generationLogs(int $familyId): array
    {
        return Db::fetchAll(
            'SELECT id, provider, model, prompt_version, output_version, review_status, created_at
             FROM ai_generation_logs WHERE family_id = ? ORDER BY id DESC LIMIT 100',
            [$familyId]
        );
    }

    /** 输入授权：外部资料必须先通过现有可见性检查，禁止仅凭 ID 调用 AI。 */
    private static function assertInputAuthorized(?array $user, int $familyId, string $type, array $input): void
    {
        foreach (['archive_id', 'file_id'] as $key) {
            if (!empty($input[$key])) {
                $archiveId = (int) $input[$key];
                if ($key === 'file_id') {
                    $file = ArchiveService::getFileVisible($user, $archiveId);
                    if ($file === null) {
                        throw new HttpException(404, '档案文件不存在或无权访问');
                    }
                } elseif (ArchiveService::getVisible($user, $archiveId) === null) {
                    throw new HttpException(404, '档案不存在或无权访问');
                }
            }
        }
        if (!empty($input['person_id']) && FamilyPersonService::getVisible($user, (int) $input['person_id']) === null) {
            throw new HttpException(404, '人物不存在或无权访问');
        }
        if (!empty($input['session_id'])) {
            $session = self::session((int) $input['session_id']);
            if ($session === null || (int) $session['family_id'] !== $familyId) {
                throw new HttpException(404, '采访不存在或不属于该家族');
            }
            if (($session['consent_status'] ?? '') !== 'granted') {
                throw new HttpException(422, '未获得受访者授权，不能处理采访内容');
            }
        }
        if ($type === 'transcribe_audio' && empty($input['file_id']) && empty($input['session_id'])) {
            throw new HttpException(422, '转写任务必须提供档案文件或采访记录');
        }
    }

    private static function run(string $type, array $input): array
    {
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '' && in_array($type, ['extract_facts', 'biography_draft', 'family_history_draft'], true)) {
            throw new HttpException(422, '缺少待整理的文字资料');
        }
        $client = AiClientFactory::makeFor('text');
        $system = '你是家族记忆整理助手。只能依据用户提供的资料输出草稿，不得编造人物、日期或事件；'
            . '不确定之处必须标注「待核实」。';
        $prompt = match ($type) {
            'ocr_document' => '请将以下 OCR 文本整理为可阅读的档案文字，保留原文事实并标注不确定处：\n' . $text,
            'transcribe_audio' => '请将以下转写稿分段并标注说话人，不得新增内容：\n' . $text,
            'extract_facts' => '请从以下资料提取人物、时间、地点、事件候选，并逐条标注来源位置：\n' . $text,
            'biography_draft' => '请依据以下资料撰写人物传记草稿，并列出待补充问题与来源清单：\n' . $text,
            'family_history_draft' => '请依据以下资料撰写家族史草稿，资料不足处标注待核实：\n' . $text,
            'oral_assist' => '请根据已回答内容提出下一轮采访问题，允许受访者跳过或修改：\n' . $text,
            default => throw new HttpException(422, '不支持的 AI 工作流类型'),
        };
        $content = $client !== null ? $client->generateText($system, $prompt, 1800) : self::localDraft($type, $text);

        $facts = [];
        if (in_array($type, ['extract_facts', 'ocr_document', 'biography_draft', 'family_history_draft'], true)) {
            foreach (self::extractCandidates($text) as $candidate) {
                $facts[] = [
                    'fact_type' => $candidate[0],
                    'fact_content' => $candidate[1],
                    'source_type' => $type === 'ocr_document' ? 'archive_ocr' : 'ai_extraction',
                    'confidence' => 0.5,
                    'verification_status' => 'pending',
                ];
            }
        }
        return [
            'type' => $type,
            'content' => mb_substr($content, 0, 20000),
            'facts' => $facts,
            'note' => 'AI 输出仅为草稿/候选，未经人工审核不得作为正式事实。',
            'generated_at' => date('c'),
        ];
    }

    /** 本地演示模板：只重新排版输入内容，不生成任何新事实。 */
    private static function localDraft(string $type, string $text): string
    {
        $heading = match ($type) {
            'ocr_document' => '档案文字整理稿（待核实）',
            'transcribe_audio' => '采访转写整理稿（待核实）',
            'extract_facts' => '人物与事件候选（待核实）',
            'biography_draft' => '人物传记草稿（待核实）',
            'family_history_draft' => '家族史草稿（待核实）',
            'oral_assist' => '下一轮采访问题建议（可修改或跳过）',
            default => 'AI 草稿（待核实）',
        };
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn ($v) => $v !== ''));
        $body = '';
        foreach (array_slice($lines, 0, 40) as $index => $line) {
            $body .= ($index + 1) . '. ' . mb_substr($line, 0, 300) . "\n";
        }
        return $heading . "\n\n" . ($body !== '' ? $body : '（资料为空）') . "\n\n资料来源：本次提交的原文；未核实信息请人工补充。";
    }

    /** 极保守候选提取：只取原文中的日期片段与显式称谓短语，全部待审核。 */
    private static function extractCandidates(string $text): array
    {
        $out = [];
        if (preg_match_all('/(\d{4})\s*年\s*(\d{1,2})\s*月/u', $text, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 10) as $hit) {
                $out[] = ['date', $hit[1] . '年' . $hit[2] . '月（原文提及，待核实）'];
            }
        }
        if (preg_match_all('/(父亲|母亲|祖父|祖母|配偶|儿子|女儿)/u', $text, $m)) {
            foreach (array_unique(array_slice($m[1], 0, 10)) as $word) {
                $out[] = ['relationship', $word . '（原文称谓，关系方向待核实）'];
            }
        }
        return $out;
    }
}
