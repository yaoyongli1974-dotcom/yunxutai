# 云叙台 v2.1.1 — Phase 0 AI 工作流分析

基线：`e723d07`（行为等于 v2.1.0）。标注：**CONFIRMED**（代码证据）/ **NEEDS_VERIFICATION**。

## 1. 两套 AI 栈（CONFIRMED）

- **v2 工作流**：`AiWorkflowService` + `ai_workflows/ai_workflow_steps/memory_facts/oral_history_*` + `bin/ai-workflow-worker.php`。Web 入口 `AiWorkshopController`，API 入口 `FamilyV2Controller`。
- **legacy**：`AiJob` + `AiStudioService` + `bin/ai-worker.php` + `ai_jobs/ai_orders`。订单确认会创建 legacy AiJob，两套栈不共享状态机。

## 2. 实际函数与调用关系（CONFIRMED）

`AiWorkflowService.php` 关键函数：
- `create`（:35）：Policy → `assertInputAuthorized` → 幂等键哈希 → 事务写 workflow + steps → 审计。
- `claimStrict`（:121，`FOR UPDATE SKIP LOCKED`）/ `claim`（:161）/ `heartbeat`（:167）/ `owns`（:182）/ `recoverExpired`（:193）。
- `process`（:282）：fence → `find` → 必要时 `claim` → `assertRunAuthorized` → `run` → 事务写结果/日志/通知 → catch 分类重试。
- `cancel`（:403）：`cancelled` + `lock_version++` + steps `queued|failed → skipped`。
- `addFactCandidate`（:429）/`facts`（:492）/`reviewFact`（:504，CAS + owner/admin）。
- 口述历史：`createOralSession`（:537）/`setConsent`（:556）/`sessions`（:573）/`addSegment`（:609）/`reviewSegment`（:630）/`sessionAudio`（:825）/`transcribeSession`（:853）/`revokeSessionConsent`（:863）。
- 校验：`assertInputAuthorized`（:645）、`consentRevokedForWorkflow`（:678）、`assertRunAuthorized`（:694）、`run`（:708）、`extractCandidates`（:788）。

## 3. 状态与转换（CONFIRMED）

- `ai_workflows.status`：`queued|processing|completed|failed|cancelled`；steps：`queued|processing|completed|failed|skipped`。
- Create → `queued` + steps `queued`；claim → `processing` + lease/lock_version；success → 全部 steps **批量** `completed`（:316-324）+ workflow `completed` + generation log；failure → 仅 `status='processing'` 的 steps 置 failed（实际通常无操作），workflow `queued|failed`；cancel → `cancelled` + steps skipped。
- **R-10 OPEN**：step 从未被置为 `processing`；一次批量循环把未执行步骤标为 `completed`，状态不真实。
- 租约 900s 硬编码（:143/:171），心跳 60s；CLI `max_runtime=300` 仅在任务间检查（worker :93）。配置 `.env.example` 只有 `AI_TIMEOUT=120`，**无 workflow/deadline/step timeout/lease/backoff 配置项**。

## 4. 可信度与审核（CONFIRMED）

- `memory_facts` 有 `source_type/source_location/confidence`，迁移 `20261128_002` 追加 `source_archive_id/source_version_id/source_excerpt/subject_type/subject_id`。
- `addFactCandidate` 固定写 `verification_status='pending'`（:485），AI 不能写 `confirmed`；`reviewFact` 仅 owner/admin 且 CAS，禁止 `confirmed↔rejected` 直转。
- 来源字段写入时**未校验** `source_archive_id/source_version_id` 归属，`source_location` 从未写入；工作流输出候选只留在 `output_json`，未落 `memory_facts`。
- 同意校验覆盖创建、运行前、恢复、音频访问，但 `facts()`/`reviewFact()` 不校验同意。

## 5. 订单与幂等（CONFIRMED / NEEDS_VERIFICATION）

- AI workflow 幂等：`hash(family|type|key)` + 唯一键 + PDO 23000 回查（:43-88）；Web 手动 `runWorkflow` 在无 token 时会 `claim(...,1000)`，可能把其他任务一并置为 processing（:294-300）。
- AiOrder `status` 含 `cancelled` 但**无代码写入**；订单无幂等键；`confirm()` 有 `FOR UPDATE`（`AiOrderController.php:54`），但吞掉 `AiStudioService::process()` 异常，可能订单 paid 而任务 failed（:85-99）。10 路并发确认仅静态分析，未自动化（NEEDS_VERIFICATION）。

## 6. 本轮 Phase 1/2/3 重点

1. 真实步骤状态机（`pending→running→completed/failed/skipped/cancelled`）+ 状态历史 + API 返回真实状态（Phase 1）。
2. 全局截止时间/步骤超时/租约/取消/重试配置与执行检查（Phase 2）。
3. 事实来源校验、审核状态扩展、AI 生成标识（Phase 3）。
4. AiOrder 幂等与事务边界纳入 Phase 6。

详见 `06_REFACTORING_PLAN.md`。
