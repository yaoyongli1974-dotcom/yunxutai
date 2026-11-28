<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Policy;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\ProcessingClient;
use App\Services\Ai\ProcessingException;
use PDOException;
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

    /** 步骤状态转换规则：queued ≡ pending，processing ≡ running（兼容既有前端标签）。 */
    private const STEP_TRANSITIONS = [
        'queued' => ['processing', 'cancelled'],
        'processing' => ['completed', 'awaiting_review', 'failed', 'cancelled', 'queued'],
        'failed' => ['processing', 'cancelled'],
        'awaiting_review' => ['approved', 'rejected'],
        'completed' => [],
        'skipped' => [],
        'cancelled' => [],
        'approved' => [],
        'rejected' => [],
    ];

    /** 工作流总超时（秒），带类型/上下限校验。 */
    private static function workflowTimeout(): int
    {
        return max(60, min(86400, (int) config('ai.workflow_timeout_seconds', 1800)));
    }

    /** 单步骤超时（秒），不得大于总超时。 */
    private static function stepTimeout(): int
    {
        return max(5, min(3600, min(self::workflowTimeout(), (int) config('ai.step_timeout_seconds', 300))));
    }

    /** Worker 租约（秒），续租按三分之一周期执行。 */
    private static function leaseSeconds(): int
    {
        return max(60, min(7200, (int) config('ai.worker_lease_seconds', 900)));
    }

    /** 最大重试次数，全局上限优先于单行配置。 */
    private static function maxRetries(): int
    {
        return max(0, min(10, (int) config('ai.max_retries', 3)));
    }

    /** 重试退避上限（秒）。 */
    private static function retryBackoff(): int
    {
        return max(1, min(3600, (int) config('ai.retry_backoff_seconds', 60)));
    }

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

        try {
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
                    'deadline_at' => date('Y-m-d H:i:s', time() + self::workflowTimeout()),
                    'workflow_timeout_seconds' => self::workflowTimeout(),
                    'step_timeout_seconds' => self::stepTimeout(),
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
        } catch (PDOException $e) {
            // 并发创建同一幂等键时唯一键冲突：回查已落库任务，保持幂等语义
            if (($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
            $existing = Db::value(
                'SELECT id FROM ai_workflows WHERE family_id = ? AND workflow_type = ? AND idempotency_key = ? LIMIT 1',
                [$familyId, $type, $key]
            );
            if ($existing === null) {
                throw $e; // 非重复键导致的外键等完整性错误仍按原异常抛出
            }
            return (int) $existing;
        }

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

    /**
     * 严格领取：FOR UPDATE SKIP LOCKED + lock_version 原子递增，返回带 fencing token 的任务。
     * MySQL 8.0+ / MariaDB 10.6+ 均支持 SKIP LOCKED。
     *
     * @return list<array{id:int,lock_version:int,workflow_type:string}>
     */
    public static function claimStrict(string $workerId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        return Db::transaction(static function () use ($workerId, $limit): array {
            $rows = Db::fetchAll(
                "SELECT id, workflow_type, lock_version FROM ai_workflows
                 WHERE status IN ('queued', 'failed') AND run_after <= NOW()
                   AND retry_count < max_retries
                   AND (deadline_at IS NULL OR deadline_at > NOW())
                 ORDER BY id ASC LIMIT {$limit}
                 FOR UPDATE SKIP LOCKED"
            );
            $claimed = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $step = Db::fetchAll(
                    "SELECT name FROM ai_workflow_steps WHERE workflow_id = ? AND status IN ('queued','failed') ORDER BY step_no LIMIT 1",
                    [$id]
                );
                $stepName = $step[0]['name'] ?? 'validate_input';
                $updated = Db::update('ai_workflows', [
                    'status' => 'processing',
                    'locked_by' => $workerId,
                    'lease_until' => date('Y-m-d H:i:s', time() + self::leaseSeconds()),
                    'heartbeat_at' => date('Y-m-d H:i:s'),
                    'current_step' => $stepName,
                    'started_at' => date('Y-m-d H:i:s'),
                    'lock_version' => (int) $row['lock_version'] + 1,
                ], "id = ? AND status IN ('queued','failed') AND run_after <= NOW() AND retry_count < max_retries
                    AND (deadline_at IS NULL OR deadline_at > NOW())", [$id]);
                if ($updated !== 1) {
                    continue;
                }
                $version = (int) Db::value('SELECT lock_version FROM ai_workflows WHERE id = ?', [$id]);
                $claimed[] = ['id' => $id, 'lock_version' => $version, 'workflow_type' => (string) $row['workflow_type']];
                Audit::log('ai.workflow.claimed', 'ai_workflow', $id, ['worker' => $workerId, 'lock_version' => $version]);
            }
            return $claimed;
        });
    }

    /** 兼容旧调用：只返回任务 ID 列表；真正 Worker 使用 claimStrict()。 */
    public static function claim(string $workerId, int $limit = 5): array
    {
        return array_map(static fn (array $row): int => $row['id'], self::claimStrict($workerId, $limit));
    }

    /** 只有当前持锁 Worker（且租约未过期）可以续租。 */
    public static function heartbeat(int $id, string $workerId, int $lockVersion): bool
    {
        $updated = Db::update(
            'ai_workflows',
            ['heartbeat_at' => date('Y-m-d H:i:s'), 'lease_until' => date('Y-m-d H:i:s', time() + self::leaseSeconds())],
            "id = ? AND status = 'processing' AND locked_by = ? AND lock_version = ?
             AND (lease_until IS NULL OR lease_until > NOW())",
            [$id, $workerId, $lockVersion]
        );
        // MySQL 对「值未发生实际变化」的 UPDATE 返回 rowCount=0；
        // 同秒内续租即属此情况，再用所有权校验确认不是被其他 Worker 抢走。
        return $updated === 1 || ($updated === 0 && self::owns($id, $workerId, $lockVersion));
    }

    /** 严格校验 Worker 所有权；失败时旧 Worker 不得写入任何结果。 */
    public static function owns(int $id, string $workerId, int $lockVersion): bool
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM ai_workflows
             WHERE id = ? AND status = 'processing' AND locked_by = ? AND lock_version = ?
               AND (lease_until IS NULL OR lease_until > NOW())",
            [$id, $workerId, $lockVersion]
        ) === 1;
    }

    /** 租约超时恢复：条件 UPDATE + fencing 版本递增 + 审计 + 指数退避重排。 */
    public static function recoverExpired(): array
    {
        $expired = Db::fetchAll(
            "SELECT id, locked_by, lock_version, retry_count FROM ai_workflows
             WHERE status = 'processing' AND lease_until IS NOT NULL AND lease_until < NOW()"
        );
        $recovered = 0;
        foreach ($expired as $row) {
            $id = (int) $row['id'];
            if (self::consentRevokedForWorkflow($id)) {
                $ok = Db::update('ai_workflows', [
                    'status' => 'cancelled',
                    'failure_class' => 'consent_revoked',
                    'error_message' => '受访者已撤回授权，任务取消且不再重试',
                    'last_error_at' => date('Y-m-d H:i:s'),
                    'locked_by' => null,
                    'lease_until' => null,
                    'heartbeat_at' => null,
                    'lock_version' => (int) $row['lock_version'] + 1,
                ], "id = ? AND status = 'processing' AND lease_until IS NOT NULL AND lease_until < NOW()
                    AND locked_by = ? AND lock_version = ?", [$id, (string) $row['locked_by'], (int) $row['lock_version']]);
                if ($ok === 1) {
                    Audit::log('ai.workflow.consent_revoked', 'ai_workflow', $id, [
                        'previous_worker' => (string) $row['locked_by'],
                    ]);
                }
                continue;
            }
            $deadline = Db::value('SELECT deadline_at FROM ai_workflows WHERE id = ?', [$id]);
            if ($deadline !== null && strtotime((string) $deadline) <= time()) {
                $ok = Db::update('ai_workflows', [
                    'status' => 'cancelled',
                    'failure_class' => 'workflow_timeout',
                    'error_message' => '工作流已超过全局截止时间，不再重试',
                    'last_error_at' => date('Y-m-d H:i:s'),
                    'locked_by' => null,
                    'lease_until' => null,
                    'heartbeat_at' => null,
                    'lock_version' => (int) $row['lock_version'] + 1,
                ], "id = ? AND status = 'processing' AND lease_until IS NOT NULL AND lease_until < NOW()
                    AND locked_by = ? AND lock_version = ?", [$id, (string) $row['locked_by'], (int) $row['lock_version']]);
                if ($ok === 1) {
                    Audit::log('ai.workflow.timeout', 'ai_workflow', $id, [
                        'workflow_id' => $id,
                        'previous_worker' => (string) $row['locked_by'],
                        'worker_id' => (string) $row['locked_by'],
                        'error_code' => 'workflow_timeout',
                    ]);
                }
                continue;
            }
            $max = min((int) Db::value('SELECT max_retries FROM ai_workflows WHERE id = ?', [$id]), self::maxRetries());
            $retry = min((int) $row['retry_count'] + 1, $max);
            $ok = Db::update('ai_workflows', [
                'status' => $retry < $max ? 'queued' : 'failed',
                'failure_class' => 'lease_expired',
                'error_message' => 'Worker 租约超时，任务已回收',
                'last_error_at' => date('Y-m-d H:i:s'),
                'locked_by' => null,
                'lease_until' => null,
                'heartbeat_at' => null,
                'lock_version' => (int) $row['lock_version'] + 1,
                'retry_count' => $retry,
                'run_after' => date('Y-m-d H:i:s', time() + ($retry < $max ? min(self::retryBackoff(), 2 ** ($retry - 1)) : 0)),
            ], "id = ? AND status = 'processing' AND lease_until IS NOT NULL AND lease_until < NOW()
                AND locked_by = ? AND lock_version = ?", [$id, (string) $row['locked_by'], (int) $row['lock_version']]);
            if ($ok !== 1) {
                continue;
            }
            $recovered++;
            Audit::log('ai.workflow.lease_expired', 'ai_workflow', $id, [
                'workflow_id' => $id,
                'previous_worker' => (string) $row['locked_by'],
                'worker_id' => (string) $row['locked_by'],
                'retry' => $retry,
                'error_code' => 'lease_expired',
            ]);
        }
        $retried = 0;
        $failed = Db::fetchAll(
            "SELECT id, retry_count FROM ai_workflows WHERE status = 'failed' AND retry_count < max_retries AND run_after <= NOW()"
        );
        foreach ($failed as $row) {
            if (self::consentRevokedForWorkflow((int) $row['id'])) {
                $ok = Db::update(
                    'ai_workflows',
                    [
                        'status' => 'cancelled',
                        'failure_class' => 'consent_revoked',
                        'error_message' => '受访者已撤回授权，任务取消且不再重试',
                        'last_error_at' => date('Y-m-d H:i:s'),
                        'locked_by' => null,
                        'lease_until' => null,
                    ],
                    "id = ? AND status = 'failed' AND retry_count < max_retries",
                    [(int) $row['id']]
                );
                if ($ok === 1) {
                    Audit::log('ai.workflow.consent_revoked', 'ai_workflow', (int) $row['id'], []);
                }
                continue;
            }
            $delay = min(60, 2 ** (int) $row['retry_count']);
            $ok = Db::update(
                'ai_workflows',
                ['status' => 'queued', 'run_after' => date('Y-m-d H:i:s', time() + $delay)],
                "id = ? AND status = 'failed' AND retry_count < max_retries",
                [(int) $row['id']]
            );
            $retried += $ok;
        }
        return ['expired' => $recovered, 'requeued' => $retried];
    }

    /** 执行任务；online handler 走 AI Provider，未配置时用本地草稿模板，绝不编造为事实。 */
    public static function process(int $id, string $workerId = 'inline', ?int $lockVersion = null): array
    {
        if ($lockVersion !== null && !self::owns($id, $workerId, $lockVersion)) {
            throw new RuntimeException('任务持有权校验失败，拒绝写入结果');
        }
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
        if ($lockVersion === null) {
            // Web 入口未携带 fencing token：领取后回读当前版本，后续成功/失败写入均按此版本围栏
            $lockVersion = (int) Db::value('SELECT lock_version FROM ai_workflows WHERE id = ?', [$id]);
        }

        $type = (string) $workflow['workflow_type'];
        $input = json_decode((string) $workflow['input_json'], true) ?: [];
        $familyId = (int) $workflow['family_id'];
        try {
            self::assertRunAuthorized($type, $input, $familyId);
            $deadlineAt = $workflow['deadline_at'] ?? null;
            if ($deadlineAt !== null && strtotime((string) $deadlineAt) <= time()) {
                throw new RuntimeException('workflow_timeout');
            }
            $stepBudgetEnd = time() + self::stepTimeout();
            // R-10：步骤状态由真实执行过程驱动；执行在事务外，最终一次性原子写入。
            $executionId = bin2hex(random_bytes(16));
            $currentStepName = null;
            $stepWrites = [];
            $output = null;
            $steps = Db::fetchAll('SELECT * FROM ai_workflow_steps WHERE workflow_id = ? ORDER BY step_no', [$id]);
            $generatingSteps = ['recognize', 'transcribe', 'extract_candidates', 'compose', 'validate_consent'];
            foreach ($steps as $step) {
                $currentStepName = (string) $step['name'];
                if (!self::owns($id, $workerId, $lockVersion)) {
                    throw new RuntimeException('任务持有权在步骤执行前失效');
                }
                // 每步执行前重新读取任务状态：取消/全局超时/步骤预算耗尽都必须停止。
                $live = Db::fetch('SELECT status, deadline_at FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
                if ($live === null || (string) $live['status'] === 'cancelled') {
                    throw new HttpException(409, '任务已取消，不再继续执行');
                }
                if (($live['deadline_at'] ?? null) !== null && strtotime((string) $live['deadline_at']) <= time()) {
                    throw new RuntimeException('workflow_timeout');
                }
                if (time() >= $stepBudgetEnd) {
                    throw new RuntimeException('step_timeout');
                }
                $from = (string) $step['status'];
                if (!isset(self::STEP_TRANSITIONS[$from]) || !in_array('processing', self::STEP_TRANSITIONS[$from], true)) {
                    throw new RuntimeException('步骤当前状态不允许执行：' . $currentStepName);
                }
                $startedAt = date('Y-m-d H:i:s');
                // 真正的模型/OCR 调用只发生一次，且发生在需要产出内容的步骤。
                if ($output === null && in_array($currentStepName, $generatingSteps, true)) {
                    $output = self::run($type, $input);
                }
                $target = $currentStepName === 'review_draft' ? 'awaiting_review' : 'completed';
                $stepWrites[] = [
                    'id' => (int) $step['id'],
                    'name' => $currentStepName,
                    'from' => $from,
                    'to' => $target,
                    'started_at' => $startedAt,
                    'finished_at' => date('Y-m-d H:i:s'),
                    'attempt' => (int) ($step['step_attempt'] ?? 0) + 1,
                    'output_ref' => $target === 'awaiting_review' ? 'pending_review' : 'output_json',
                    'execution_id' => $executionId,
                ];
            }
            if ($output === null) {
                $output = self::run($type, $input);
            }
            Db::transaction(static function () use ($id, $familyId, $workflow, $input, $output, $workerId, $lockVersion, $stepWrites, $executionId): void {
                if (!self::owns($id, $workerId, $lockVersion)) {
                    throw new RuntimeException('任务持有权在提交前失效');
                }
                foreach ($stepWrites as $write) {
                    $updated = Db::update('ai_workflow_steps', [
                        'status' => $write['to'],
                        'input_ref' => json_encode(['workflow_id' => $id, 'family_id' => $familyId], JSON_UNESCAPED_UNICODE),
                        'output_ref' => $write['output_ref'],
                        'error_message' => null,
                        'step_error_code' => null,
                        'step_attempt' => $write['attempt'],
                        'execution_id' => $executionId,
                        'step_updated_at' => $write['finished_at'],
                        'started_at' => $write['started_at'],
                        'finished_at' => $write['finished_at'],
                    ], 'id = ? AND status = ?', [(int) $write['id'], $write['from']]);
                    if ($updated !== 1) {
                        throw new RuntimeException('步骤状态已被其他执行者更新：' . $write['name']);
                    }
                    self::recordStepTransition($id, (int) $write['id'], (string) $write['name'], $write['from'], $write['to'], [
                        'worker_id' => $workerId,
                        'execution_id' => $executionId,
                        'attempt' => $write['attempt'],
                    ]);
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
                ], "id = ? AND status = 'processing' AND locked_by = ? AND lock_version = ?",
                    [$id, $workerId, $lockVersion]);
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
            // R-23：仅在工作流首次成功提交后发一条站内完成通知；通知失败不回滚业务结果。
            try {
                $link = '/families/' . $familyId . '/ai?workflow=' . $id;
                $exists = (int) Db::value(
                    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND link = ?',
                    [(int) $workflow['created_by'], 'ai_workflow_completed', $link]
                );
                if ($exists === 0) {
                    \App\Services\NotificationService::emit(
                        $familyId,
                        (int) $workflow['created_by'],
                        'ai_workflow_completed',
                        'AI 整理任务已完成',
                        '任务 #' . $id . ' 已完成，请人工审核候选事实后再采用。',
                        $link,
                        false,
                        'ai_workflow_completed:' . $id
                    );
                }
            } catch (\Throwable $e) {
                error_log('ai.workflow.notify failed [workflow=' . $id . '][worker=' . $workerId
                    . '][error_code=' . get_class($e) . ']: ' . hash('sha256', $e->getMessage()));
            }
            return self::find($id) ?? $workflow;
        } catch (Throwable $e) {
            $retry = (int) $workflow['retry_count'] + 1;
            $max = min((int) $workflow['max_retries'], self::maxRetries());
            $message = $e->getMessage();
            if ($e instanceof HttpException) {
                $class = 'input_error';
            } elseif ($message === 'workflow_timeout') {
                $class = 'workflow_timeout';
            } elseif ($message === 'step_timeout') {
                $class = 'step_timeout';
            } elseif ($e instanceof ProcessingException) {
                $class = $e->category;
            } else {
                $class = 'provider_error';
            }
            // 参数/授权、超时、取消、Provider 明确不可重试的错误都不再自动重试
            if ($class === 'input_error' || in_array($class, ['workflow_timeout', 'step_timeout'], true)
                || ($e instanceof ProcessingException && $e->retryable === false)) {
                $retry = $max;
            }
            $status = $retry < $max ? 'queued' : 'failed';
            $delay = min(self::retryBackoff(), 2 ** max(0, $retry - 1));
            $failedStepName = $currentStepName ?? null;
            Db::transaction(static function () use ($id, $status, $retry, $class, $delay, $e, $workerId, $lockVersion, $failedStepName): void {
                if (!self::owns($id, $workerId, $lockVersion)) {
                    return; // 租约已失效/被取消：旧 Worker 不得写入任何失败状态
                }
                if ($failedStepName !== null) {
                    $step = Db::fetch(
                        'SELECT * FROM ai_workflow_steps WHERE workflow_id = ? AND name = ? LIMIT 1',
                        [$id, $failedStepName]
                    );
                    if ($step !== null && in_array((string) $step['status'], ['queued', 'processing', 'failed'], true)) {
                        $from = (string) $step['status'];
                        Db::update('ai_workflow_steps', [
                            'status' => 'failed',
                            'error_message' => mb_substr($e->getMessage(), 0, 200),
                            'step_error_code' => mb_substr($class, 0, 64),
                            'step_updated_at' => date('Y-m-d H:i:s'),
                            'finished_at' => date('Y-m-d H:i:s'),
                        ], 'id = ? AND status = ?', [(int) $step['id'], $from]);
                        self::recordStepTransition($id, (int) $step['id'], $failedStepName, $from, 'failed', [
                            'worker_id' => $workerId,
                            'error_code' => $class,
                            'attempt' => (int) ($step['step_attempt'] ?? 0) + 1,
                        ]);
                    }
                }
                Db::update('ai_workflows', [
                    'status' => $status,
                    'retry_count' => $retry,
                    'failure_class' => $class,
                    'error_message' => mb_substr($e->getMessage(), 0, 200),
                    'lease_until' => null,
                    'locked_by' => null,
                    'run_after' => date('Y-m-d H:i:s', time() + ($status === 'queued' ? $delay : 0)),
                    'last_error_at' => date('Y-m-d H:i:s'),
                ], "id = ? AND status = 'processing' AND locked_by = ? AND lock_version = ?",
                    [$id, $workerId, $lockVersion]);
            });
            Audit::log('ai.workflow.failed', 'ai_workflow', $id, [
                'class' => $class, 'retry' => $retry, 'error_hash' => hash('sha256', $e->getMessage()),
                'workflow_id' => $id, 'error_code' => $class,
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
        // 取消时递增 fencing 版本：持旧版本的 Worker 此后无法写回处理结果
        Db::query(
            "UPDATE ai_workflows SET status = 'cancelled', lease_until = NULL, locked_by = NULL,
             lock_version = lock_version + 1, finished_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), $id]
        );
        Db::transaction(static function () use ($id, $user): void {
            $steps = Db::fetchAll(
                "SELECT id, name, status FROM ai_workflow_steps
                 WHERE workflow_id = ? AND status IN ('queued','failed','processing','awaiting_review') ORDER BY step_no",
                [$id]
            );
            foreach ($steps as $step) {
                $from = (string) $step['status'];
                $updated = Db::update(
                    'ai_workflow_steps',
                    ['status' => 'cancelled', 'step_updated_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')],
                    'id = ? AND status = ?',
                    [(int) $step['id'], $from]
                );
                if ($updated === 1) {
                    self::recordStepTransition($id, (int) $step['id'], (string) $step['name'], $from, 'cancelled', [
                        'actor_type' => 'user',
                        'actor_id' => (int) ($user['id'] ?? 0),
                    ]);
                }
            }
        });
        Audit::log('ai.workflow.cancelled', 'ai_workflow', $id, ['family_id' => $familyId], (int) ($user['id'] ?? 0));
    }

    /** 记录一步状态转换；与步骤更新共用同一事务。 */
    private static function recordStepTransition(
        int $workflowId,
        ?int $stepId,
        ?string $stepName,
        ?string $from,
        string $to,
        array $context = []
    ): void {
        Db::insert('ai_workflow_step_transitions', [
            'workflow_id' => $workflowId,
            'step_id' => $stepId,
            'step_name' => $stepName === null ? null : mb_substr($stepName, 0, 60),
            'previous_status' => $from,
            'new_status' => $to,
            'actor_type' => (string) ($context['actor_type'] ?? 'worker'),
            'actor_id' => isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            'worker_id' => isset($context['worker_id']) ? mb_substr((string) $context['worker_id'], 0, 128) : null,
            'execution_id' => $context['execution_id'] ?? null,
            'attempt' => (int) ($context['attempt'] ?? 0),
            'error_code' => isset($context['error_code']) ? mb_substr((string) $context['error_code'], 0, 64) : null,
            'request_id' => $GLOBALS['request_id'] ?? null,
        ]);
    }

    /** 人工审核步骤：awaiting_review → approved / rejected，保留转换历史。 */
    public static function reviewStep(?array $user, int $stepId, string $status): void
    {
        $step = Db::fetch('SELECT * FROM ai_workflow_steps WHERE id = ? LIMIT 1', [$stepId]);
        if ($step === null) {
            throw new HttpException(404, '步骤不存在');
        }
        $workflow = self::find((int) $step['workflow_id']);
        if ($workflow === null) {
            throw new HttpException(404, '任务不存在');
        }
        $familyId = (int) $workflow['family_id'];
        Policy::require('ai.result.review', ['family_id' => $familyId, 'resource' => null], $user);
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new HttpException(422, '审核状态只能是 approved 或 rejected');
        }
        Db::transaction(static function () use ($step, $stepId, $status, $workflow, $user): void {
            $current = Db::fetch('SELECT status FROM ai_workflow_steps WHERE id = ? FOR UPDATE', [$stepId]);
            if ($current === null || (string) $current['status'] !== 'awaiting_review') {
                throw new HttpException(409, '该步骤当前不处于待审核状态');
            }
            $updated = Db::update(
                'ai_workflow_steps',
                ['status' => $status, 'step_updated_at' => date('Y-m-d H:i:s'), 'finished_at' => date('Y-m-d H:i:s')],
                'id = ? AND status = ?',
                [$stepId, 'awaiting_review']
            );
            if ($updated !== 1) {
                throw new HttpException(409, '步骤状态已被其他审核者更新');
            }
            self::recordStepTransition((int) $workflow['id'], $stepId, (string) $step['name'], 'awaiting_review', $status, [
                'actor_type' => 'user',
                'actor_id' => (int) ($user['id'] ?? 0),
            ]);
        });
        Audit::log('ai.step.reviewed', 'ai_workflow_step', $stepId, [
            'workflow_id' => (int) $workflow['id'], 'family_id' => $familyId, 'status' => $status,
        ], (int) ($user['id'] ?? 0));
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
        float $confidence = 0.5,
        ?string $sourceExcerpt = null,
        ?int $sourceArchiveId = null,
        ?int $sourceVersionId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $workflowId = null
    ): int {
        if (!in_array($sourceType, ['user_input', 'oral_history', 'archive_ocr', 'ai_extraction', 'family_confirmation', 'imported'], true)) {
            throw new HttpException(422, '未知的事实来源类型');
        }
        Policy::require('ai.workflow.create', ['family_id' => $familyId, 'resource' => null], $user);
        if ($personId !== null) {
            $person = Db::fetch('SELECT family_id FROM family_persons WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$personId]);
            if ($person === null || (int) $person['family_id'] !== $familyId) {
                throw new HttpException(404, '人物不属于该家族');
            }
        }
        if ($sourceId !== null) {
            $table = match ($sourceType) {
                'oral_history' => 'oral_history_sessions',
                'archive_ocr', 'ai_extraction' => 'archives',
                default => null,
            };
            if ($table !== null) {
                $src = Db::fetch("SELECT family_id FROM {$table} WHERE id = ? LIMIT 1", [$sourceId]);
                if ($src === null || (int) $src['family_id'] !== $familyId) {
                    throw new HttpException(404, '来源资料不属于该家族');
                }
            }
        }
        if ($subjectType === null || $subjectId === null) {
            // 未显式指定主体时：人物事实归属人物，其余归属家族
            $subjectType = $personId !== null ? 'person' : 'family';
            $subjectId = $personId ?? $familyId;
        }
        // 来源完整性：档案/版本必须真实存在且属于同家族，否则不得作为可信来源写入。
        if ($sourceArchiveId !== null) {
            $archive = Db::fetch('SELECT id FROM archives WHERE id = ? AND family_id = ? LIMIT 1', [$sourceArchiveId, $familyId]);
            if ($archive === null) {
                throw new HttpException(404, '来源档案不存在或不属于该家族');
            }
        }
        if ($sourceVersionId !== null) {
            $version = Db::fetch(
                'SELECT v.id FROM archive_versions v INNER JOIN archives a ON a.id = v.archive_id
                 WHERE v.id = ? AND a.family_id = ? LIMIT 1',
                [$sourceVersionId, $familyId]
            );
            if ($version === null) {
                throw new HttpException(404, '来源版本不存在或不属于该家族');
            }
        }
        if ($workflowId !== null) {
            $workflow = Db::fetch('SELECT id, family_id FROM ai_workflows WHERE id = ? LIMIT 1', [$workflowId]);
            if ($workflow === null || (int) $workflow['family_id'] !== $familyId) {
                throw new HttpException(404, '来源任务不存在或不属于该家族');
            }
        }
        $factKind = match ($sourceType) {
            'user_input', 'oral_history' => 'user_provided',
            'family_confirmation' => 'family_verified',
            'archive_ocr' => 'archive_verified',
            'ai_extraction' => 'ai_inferred',
            default => 'public_source',
        };
        $sourceLocation = $sourceExcerpt !== null && $sourceArchiveId !== null
            ? 'archive:' . $sourceArchiveId . '/' . ($sourceVersionId ?? 'latest')
            : null;
        $id = Db::insert('memory_facts', [
            'family_id' => $familyId,
            'person_id' => $personId,
            'fact_type' => mb_substr($factType, 0, 40),
            'fact_content' => mb_substr($content, 0, 2000),
            'source_type' => $sourceType,
            'fact_kind' => $factKind,
            'source_id' => $sourceId,
            'source_archive_id' => $sourceArchiveId,
            'source_version_id' => $sourceVersionId,
            'source_excerpt' => $sourceExcerpt,
            'source_location' => $sourceLocation,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'workflow_id' => $workflowId,
            'confidence' => max(0, min(0.999, $confidence)),
            'verification_status' => 'pending',
            'review_status' => 'pending',
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
        $sql .= ' ORDER BY id DESC LIMIT 250';
        $rows = Db::fetchAll($sql, $params);
        return array_values(array_filter($rows, static function (array $row): bool {
            $workflowId = (int) ($row['workflow_id'] ?? 0);
            return $workflowId === 0 || !self::consentRevokedForWorkflow($workflowId);
        }));
    }

    public static function reviewFact(?array $user, int $factId, string $status, string $note = ''): void
    {
        $map = [
            'confirmed' => ['confirmed', 'approved'],
            'approved' => ['confirmed', 'approved'],
            'disputed' => ['disputed', 'needs_revision'],
            'needs_revision' => ['disputed', 'needs_revision'],
            'rejected' => ['rejected', 'rejected'],
            'pending' => ['pending', 'pending'],
        ];
        if (!isset($map[$status])) {
            throw new HttpException(422, '无效的审核状态');
        }
        [$verification, $review] = $map[$status];
        $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
        if ($fact === null) {
            throw new HttpException(404, '事实记录不存在');
        }
        Policy::require('ai.result.review', ['family_id' => (int) $fact['family_id'], 'resource' => null], $user);
        $workflowId = (int) ($fact['workflow_id'] ?? 0);
        if ($workflowId > 0 && self::consentRevokedForWorkflow($workflowId)) {
            throw new HttpException(422, '受访者已撤回授权，不能审核该事实');
        }
        $before = (string) $fact['verification_status'];
        if ($before === $verification) {
            throw new HttpException(422, '状态未发生变化');
        }
        if ($before === 'confirmed' && $verification === 'rejected') {
            throw new HttpException(422, '已确认事实不能直接驳回，请先标记为争议');
        }
        if ($before === 'rejected' && $verification === 'confirmed') {
            throw new HttpException(422, '已驳回的事实需重新提交后再审核');
        }
        $version = (int) ($fact['version'] ?? 1) + 1;
        $note = mb_substr(trim($note), 0, 500);
        $now = date('Y-m-d H:i:s');
        Db::transaction(static function () use ($fact, $factId, $verification, $review, $note, $version, $before, $now, $user): void {
            $updated = Db::update('memory_facts', [
                'verification_status' => $verification,
                'review_status' => $review,
                'review_note' => $note !== '' ? $note : null,
                'version' => $version,
                'reviewed_by' => (int) ($user['id'] ?? 0),
                'reviewed_at' => $now,
            ], 'id = ? AND verification_status = ?', [$factId, $before]);
            if ($updated !== 1) {
                throw new HttpException(409, '事实状态已被其他审核修改，请刷新后重试');
            }
            Db::insert('ai_fact_revisions', [
                'fact_id' => $factId,
                'fact_content' => (string) $fact['fact_content'],
                'verification_status' => $before,
                'review_status' => (string) ($fact['review_status'] ?? 'pending'),
                'review_note' => $fact['review_note'] ?? null,
                'version' => $version - 1,
                'actor_id' => (int) ($user['id'] ?? 0),
            ]);
        });
        Audit::log('ai.fact.reviewed', 'memory_fact', $factId, [
            'family_id' => (int) $fact['family_id'], 'from' => $before, 'to' => $verification,
            'version' => $version,
        ], (int) ($user['id'] ?? 0));
        if ($workflowId > 0) {
            self::syncWorkflowReview($workflowId, $user);
        }
    }

    /** 一个工作流的所有候选事实审核完毕后，推进其 awaiting_review 步骤。 */
    private static function syncWorkflowReview(int $workflowId, ?array $user): void
    {
        $pending = (int) Db::value(
            "SELECT COUNT(*) FROM memory_facts WHERE workflow_id = ? AND review_status = 'pending'",
            [$workflowId]
        );
        if ($pending > 0) {
            return;
        }
        $step = Db::fetch(
            "SELECT id FROM ai_workflow_steps WHERE workflow_id = ? AND status = 'awaiting_review' ORDER BY step_no LIMIT 1",
            [$workflowId]
        );
        if ($step === null) {
            return;
        }
        $rejected = (int) Db::value(
            "SELECT COUNT(*) FROM memory_facts WHERE workflow_id = ? AND review_status IN ('rejected','needs_revision')",
            [$workflowId]
        );
        $final = $rejected > 0 ? 'rejected' : 'approved';
        $logStatus = $rejected > 0 ? 'rejected' : 'confirmed';
        self::reviewStep($user, (int) $step['id'], $final);
        Db::update('ai_generation_logs', ['review_status' => $logStatus], 'workflow_id = ?', [$workflowId]);
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

    /** 会话授权复核：仅带 session_id 的工作流受此授权通道约束；无法确认授权时一律视为已撤回。 */
    private static function consentRevokedForWorkflow(int $id): bool
    {
        $workflow = self::find($id);
        if ($workflow === null) {
            return false;
        }
        $input = json_decode((string) $workflow['input_json'], true);
        if (!is_array($input) || empty($input['session_id'])) {
            return false; // 非会话工作流不受该授权通道影响
        }
        $session = self::session((int) $input['session_id']);
        $status = $session === null ? '' : (string) ($session['consent_status'] ?? '');
        return $status !== 'granted';
    }

    /** 执行前复核会话授权；抛 HttpException 以便被 process() 归类为 input_error 且不重试。 */
    private static function assertRunAuthorized(string $type, array $input, ?int $familyId = null): void
    {
        if (empty($input['session_id'])) {
            return;
        }
        $session = self::session((int) $input['session_id']);
        if ($session === null || ($session['consent_status'] ?? '') !== 'granted') {
            throw new HttpException(422, '受访者已撤回授权，任务不再执行');
        }
        if ($type === 'transcribe_audio' && $familyId !== null && (int) $session['family_id'] !== $familyId) {
            throw new HttpException(404, '采访不存在或不属于该家族');
        }
    }

    private static function run(string $type, array $input): array
    {
        $text = trim((string) ($input['text'] ?? ''));
        $processingProvider = null;
        if (($type === 'ocr_document' || $type === 'transcribe_audio') && !empty($input['file_id'])) {
            // 输入资料在创建任务时已通过 assertInputAuthorized 校验；worker 侧不再做用户级授权。
            $file = \App\Core\Db::fetch('SELECT * FROM archive_files WHERE id = ? LIMIT 1', [(int) $input['file_id']]);
            if ($file === null) {
                throw new HttpException(404, '待处理文件不存在');
            }
            $kind = $type === 'ocr_document' ? 'ocr' : 'asr';
            if (ProcessingClient::enabled($kind)) {
                $path = ArchiveService::absolutePath($file);
                if ($path === null) {
                    throw new HttpException(422, '待处理文件不可访问');
                }
                $text = ProcessingClient::recognize($kind, $path, (string) $file['mime_type'], (string) ($input['prompt'] ?? ''));
                $processingProvider = ProcessingClient::describe($kind);
            }
        }
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
            'processing_provider' => $processingProvider,
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

    /** F-01：资源级授权——工作流详情必须按 family_id 过 Policy，未授权返回 null。 */
    public static function getVisible(?array $user, int $id): ?array
    {
        $wf = self::find($id);
        if ($wf === null) {
            return null;
        }
        $familyId = (int) ($wf['family_id'] ?? 0);
        if ($familyId <= 0 || !self::familyVisible($familyId, $user)) {
            return null;
        }
        return $wf;
    }

    /** F-01：步骤读取必须先经过已授权的工作流。 */
    public static function visibleSteps(?array $user, int $id): array
    {
        return self::getVisible($user, $id) === null ? [] : self::steps($id);
    }

    /** E-02/E-03：校验会话归属与授权，返回已授权的会话与音频文件。 */
    public static function sessionAudio(?array $user, int $sessionId): array
    {
        $session = self::session($sessionId);
        if ($session === null) {
            throw new HttpException(404, '采访会话不存在');
        }
        $familyId = (int) $session['family_id'];
        if (!self::familyVisible($familyId, $user)) {
            throw new HttpException(404, '采访会话不存在');
        }
        if ((string) $session['consent_status'] !== 'granted') {
            throw new HttpException(422, '受访者未授权或已撤回授权');
        }
        $fileId = (int) ($session['audio_file_id'] ?? 0);
        if ($fileId <= 0) {
            throw new HttpException(422, '会话尚无音频文件');
        }
        $file = Db::fetch(
            'SELECT f.*, a.family_id FROM archive_files f INNER JOIN archives a ON a.id = f.archive_id WHERE f.id = ? LIMIT 1',
            [$fileId]
        );
        if ($file === null || (int) $file['family_id'] !== $familyId) {
            throw new HttpException(404, '音频文件不属于该家族会话');
        }
        return [$session, $file];
    }

    /** E-03：为已授权会话创建转写工作流；实际执行仍走 run()/ProcessingClient。 */
    public static function transcribeSession(?array $user, int $sessionId): int
    {
        [$session, $file] = self::sessionAudio($user, $sessionId);
        return self::create($user, (int) $session['family_id'], 'transcribe_audio', [
            'session_id' => $sessionId,
            'file_id' => (int) $file['id'],
        ], 'oral-' . $sessionId . '-' . md5((string) $session['updated_at'] ?? ''));
    }

    /** E-02：撤销授权时取消尚未执行的会话任务，已执行内容保留审计并限制访问。 */
    public static function revokeSessionConsent(?array $user, int $sessionId): void
    {
        [, $session] = [null, self::session($sessionId)];
        if ($session === null) {
            throw new HttpException(404, '采访会话不存在');
        }
        self::setConsent($user, $sessionId, false);
        Db::query(
            "UPDATE ai_workflows SET status = 'cancelled', locked_by = NULL, lease_until = NULL
             WHERE family_id = ? AND status IN ('queued','processing','failed')
               AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.session_id')) = ?",
            [(int) $session['family_id'], (string) $sessionId]
        );
        Audit::log('oral_history.consent_revoked', 'oral_history_session', $sessionId, [
            'family_id' => (int) $session['family_id'],
        ], $user === null ? 0 : (int) $user['id']);
    }

    private static function familyVisible(int $familyId, ?array $user): bool
    {
        $family = Db::fetch("SELECT privacy, status FROM families WHERE id = ? LIMIT 1", [$familyId]);
        if ($family === null || $family['status'] !== 'active') {
            return false;
        }
        $uid = $user === null ? null : (int) $user['id'];
        return $family['privacy'] === 'public' || \App\Core\Policy::familyRole($familyId, $uid) !== null;
    }

    /** E-04：新增片段时校验时间区间与会话家族，未审核状态固定 pending。 */
    public static function addSegmentChecked(?array $user, int $sessionId, array $data): int
    {
        $session = self::session($sessionId);
        if ($session === null || !self::familyVisible((int) $session['family_id'], $user)) {
            throw new HttpException(404, '采访不存在');
        }
        $start = max(0, (int) ($data['start_time'] ?? 0));
        $end = max(0, (int) ($data['end_time'] ?? 0));
        if ($end > 0 && $end <= $start) {
            throw new HttpException(422, '片段结束时间必须大于开始时间');
        }
        return self::addSegment($user, $sessionId, $data);
    }

    /** E-04：人工修正片段文本，保留原始转写；修正后仍为 pending，需重新审核。 */
    public static function correctSegment(?array $user, int $segmentId, string $corrected): void
    {
        $segment = Db::fetch('SELECT * FROM oral_history_segments WHERE id = ? LIMIT 1', [$segmentId]);
        if ($segment === null) {
            throw new HttpException(404, '转写片段不存在');
        }
        $session = self::session((int) $segment['session_id']);
        if ($session === null) {
            throw new HttpException(404, '采访不存在');
        }
        Policy::require('ai.workflow.create', ['family_id' => (int) $session['family_id'], 'resource' => null], $user);
        Db::update('oral_history_segments', [
            'corrected_transcript' => mb_substr($corrected, 0, 5000),
            'review_status' => 'pending',
        ], 'id = ?', [$segmentId]);
        Audit::log('oral.segment.corrected', 'oral_history_segment', $segmentId, [
            'session_id' => (int) $segment['session_id'],
        ], (int) ($user['id'] ?? 0));
    }

    /** E-04：导出已审核的完整采访文本，未审核内容不进入导出。 */
    public static function exportSessionTranscript(?array $user, int $sessionId): array
    {
        $session = self::session($sessionId);
        if ($session === null || !self::familyVisible((int) $session['family_id'], $user)) {
            throw new HttpException(404, '采访不存在');
        }
        $lines = [];
        foreach (self::segments($sessionId) as $seg) {
            if ($seg['review_status'] !== 'confirmed') {
                continue;
            }
            $text = (string) ($seg['corrected_transcript'] ?? '') ?: (string) ($seg['transcript'] ?? '');
            $lines[] = sprintf('[%s-%s] %s: %s', $seg['start_time'], $seg['end_time'], $seg['speaker'], $text);
        }
        Audit::log('oral.session.exported', 'oral_history_session', $sessionId, [
            'family_id' => (int) $session['family_id'], 'segments' => count($lines),
        ], (int) ($user['id'] ?? 0));
        return ['session_id' => $sessionId, 'lines' => $lines, 'exported_at' => date('c')];
    }
}
