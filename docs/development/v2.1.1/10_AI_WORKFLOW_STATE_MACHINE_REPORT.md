# Phase 1 — AI 工作流真实步骤状态机报告

基线：分支 `feature/v2.1.1-production-hardening`，Phase 0 提交 `942a6e1`。本阶段只改 AI 工作流状态机及其展示，不改业务语义、不删测试、不降 PHPStan。

## 阶段名称
AI 工作流真实步骤状态机（对应任务书 Phase 1）。

## 修改文件
- `app/Services/AiWorkflowService.php`（核心状态机）
- `app/Api/Controllers/FamilyV2Controller.php`（状态 API）
- `app/Views/ai/show.php`（Web 步骤展示）
- `miniprogram/pages/ai/workflow.js`（小程序状态标签）
- `tests/run.php`（新增 5 项回归测试）
- `database/migrations/20261128_006_ai_workflow_step_state_machine.php`（新增迁移）

## 修改函数 / 新增方法
- `AiWorkflowService::process()`：不再批量把所有步骤标记 completed。改为按 `TYPES` 中声明的真实步骤逐个执行，记录每步的 `started_at/finished_at/step_attempt/execution_id`；模型/OCR 调用只在需要产出的步骤发生且只调用一次；成功时 `review_draft` 进入 `awaiting_review` 而非 `completed`。所有步骤更新、状态历史写入、工作流完成与生成日志在同一个 `Db::transaction` 内以条件更新（`id=? AND status=?`）提交，并由 `owns()` 校验 worker/lock_version/lease。
- `AiWorkflowService::recordStepTransition()`（新增 private）：统一写入 `ai_workflow_step_transitions`，包含 workflow/step/前后状态/actor/worker/execution_id/attempt/error_code/request_id。
- `AiWorkflowService::reviewStep()`（新增 public）：`awaiting_review → approved|rejected`，带 `Policy::require('ai.result.review')`、FOR UPDATE 行锁与 CAS，防止重复审核；写转换历史与审计。
- `AiWorkflowService::cancel()`：取消时把 `queued/failed/processing/awaiting_review` 步骤转为 `cancelled` 并逐条写历史，而不是只改部分状态。
- `FamilyV2Controller::workflow()`：追加 `progress/step_total/step_done/can_cancel/can_retry/deadline_at/last_updated_at/error_message`；旧字段全部保留。
- Web/小程序：新增 `awaiting_review/approved/rejected/cancelled` 中文标签；Web 展示 `step_attempt`。

## 数据库迁移
`20261128_006_ai_workflow_step_state_machine`：
- `ai_workflow_steps.status` ENUM 扩展为 `queued,processing,completed,failed,skipped,cancelled,awaiting_review,approved,rejected`（保留旧标签兼容前端）。
- 新增 `step_attempt`、`execution_id`、`step_error_code`、`step_updated_at`。
- 新建 `ai_workflow_step_transitions` 表，记录所有步骤状态转换，外键到 `ai_workflows` ON DELETE CASCADE。
- 已本地应用，`migrate-v2.php --status` 显示 33 个版本全 applied。

## API 变化
仅新增响应字段，不删除/改名旧字段：`GET /api/v1/ai/workflows/{id}` 返回真实步骤进度、可执行动作与 `deadline_at`（Phase 2 实现前为 null）。

## 前端变化
Web AI 详情页与小程序 AI 工作流页可展示新步骤状态；不改变轮询频率与接口契约。

## 安全影响
- 未执行步骤不能再被批量标为完成；旧 Worker/已取消任务不能写回步骤状态（条件更新 + fencing）。
- 人工审核步骤只能由具备 `ai.result.review` 的用户推进，重复审核返回 409。
- 所有转换写入历史，可用于审计与追溯；普通日志只存 error_code/hash，不存完整敏感错误。

## 测试
`bash bin/test.sh` rc=0，**150 通过 / 0 失败**（Phase 0 的 145 + 本阶段 5 项）。新增测试覆盖：真实步骤执行与待审核、人工审核推进/重复拒绝、伪造已完成状态被拒、取消步骤转换、状态 API 真实进度字段。
其他门禁：`php -l` 全量 rc=0；`node --check` rc=0；PHPStan Level 5 `[OK] No errors`；secret-scan rc=0；迁移状态 33/33 applied；doctor rc=0（0 阻断，4 条环境警告）；`git diff --check` rc=0。
日志：`/tmp/opencode/v2.1.1/phase1/integration.log`。

## 未解决问题
- R-11（全局截止时间/步骤超时）按计划留到 Phase 2；`deadline_at` 当前恒为 null。
- 人工审核动作尚未接入 `reviewFact()` 的联动（Phase 3 处理来源与审核联动）。
- 步骤转换历史目前只对工作流步骤，不含工作流整体状态历史（Phase 2 评估是否复用现有审计）。

## 下一阶段
Phase 2：全局截止时间、步骤超时、Worker 租约配置化、取消竞态与旧 Worker 防写入增强。
