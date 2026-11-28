# Phase 2 — AI 工作流全局截止时间、步骤超时与取消防护报告

基线：Phase 1 提交 `15a610c`。分支 `feature/v2.1.1-production-hardening`。本阶段不删除测试、不降 PHPStan、不改业务结果语义。

## 阶段名称
工作流全局截止时间、步骤超时、租约配置化、取消竞态与旧 Worker 防写入。

## 修改文件
- `config/config.php`、` .env.example`（新配置项与上下限校验）
- `app/Services/AiWorkflowService.php`（截止时间写入、领取排除、执行期检查、恢复与重试分类）
- `bin/ai-workflow-worker.php`（max-runtime 默认值、心跳间隔配置化）
- `app/Api/Controllers/FamilyV2Controller.php`（返回真实 `deadline_at`）
- `database/migrations/20261128_007_ai_workflow_deadlines.php`（新增）
- `tests/run.php`（新增 4 项回归测试）

## 修改函数 / 新增方法
- 新增配置读取私有方法：`workflowTimeout()`、`stepTimeout()`、`leaseSeconds()`、`maxRetries()`、`retryBackoff()`，均带默认值与上下限钳制。
- `create()`：写入 `deadline_at = now + workflowTimeout()`、`workflow_timeout_seconds`、`step_timeout_seconds`。
- `claimStrict()`：SELECT 与条件 UPDATE 均排除 `deadline_at <= NOW()`；租约时长改为 `leaseSeconds()`。
- `heartbeat()`：续租时长改为 `leaseSeconds()`。
- `recoverExpired()`：租约超时回收时先判断全局 deadline，已过期则取消并写 `failure_class='workflow_timeout'` + 审计 `ai.workflow.timeout`；重试上限/退避改用配置。
- `process()`：执行前检查全局截止时间；每步执行前重新读取任务状态，发现取消/全局超时/步骤预算耗尽立即停止；新增 `workflow_timeout`、`step_timeout` 分类且强制不重试；退避与重试上限使用配置。
- Worker：`--max-runtime` 默认改为 `ai.workflow_timeout_seconds`；SIGALRM 续租间隔改为租约的 1/3（最小 5 秒）。
- 状态 API：`deadline_at` 返回数据库真实值。

## 数据库迁移
`20261128_007_ai_workflow_deadlines`：`ai_workflows` 增加 `deadline_at`、`workflow_timeout_seconds`、`step_timeout_seconds`，新增 `idx_ai_workflow_deadline`。本地 34 个版本全 applied，重复执行 rc=0。

## API 变化
`GET /api/v1/ai/workflows/{id}` 的 `deadline_at` 从占位 null 变为真实截止时间；其他字段不变。

## 前端变化
无需改动：小程序与 Web 已消费 Phase 1 新增的 `deadline_at` 字段（此前为 null）。

## 安全影响
- 全局超时与取消均能阻止后续步骤执行与结果提交；超时/取消不自动重试。
- 已过期任务不会被 Worker 领取；过期 Worker 仍受 lock_version/lease 双重 fencing，不能覆盖新执行者结果。
- 配置项有上下限，错误配置不会导致无限执行或负超时。

## 测试
新增 4 项：配置上下限、创建写入截止时间且 API 返回、过期不可领取且回收时按 workflow_timeout 取消、执行期超时后停止且不写步骤结果。
`bash bin/test.sh` rc=0，**154 通过 / 0 失败**（Phase 1 的 150 + 4）；日志 `/tmp/opencode/v2.1.1/phase2/integration.log`。
其他门禁：`php -l` / PHPStan Level 5 `[OK] No errors` / secret-scan / `node --check` / 迁移 34/34 applied + 重复执行 rc=0 / doctor rc=0（0 阻断、4 条环境警告）/ `git diff --check` rc=0。

## 未解决问题
- 步骤级超时目前作为执行预算并在步骤完成后校验；真正的模型调用中断依赖 provider HTTP 超时（`ai.provider_timeout_seconds`）与 Worker 租约，不实现多线程强制 kill。
- `workflow_state_transitions` 工作流级历史尚未单独建表，当前复用审计与步骤转换历史；Phase 3 评估是否补充。
- 真正 10 Worker 并发完成/取消竞态属于 Phase 6 并发一致性专项。

## 下一阶段
Phase 3：AI 事实来源、人工审核联动、内容可信度与 AI 生成标识。
