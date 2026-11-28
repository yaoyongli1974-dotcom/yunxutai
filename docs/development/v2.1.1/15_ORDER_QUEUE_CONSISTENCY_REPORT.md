# Phase 6 — 订单、AI 任务与队列并发一致性报告

基线：Phase 5 提交 `18d168f`。本阶段只做幂等、事务边界与并发一致性修复，不删测试、不降门禁、不改 API 响应形状。

## 阶段名称
订单/AI 任务/队列并发一致性（任务书 Phase 6）。

## 修改文件
- `app/Models/AiOrder.php`
- `app/Controllers/Admin/AiOrderController.php`
- `app/Controllers/Manage/AiController.php`（仅注释说明）
- `app/Services/NotificationService.php`
- `app/Services/AiWorkflowService.php`（AI 完成通知传 dedupe key）
- `database/migrations/20261128_010_order_idempotency.php`（新增）
- `database/migrations/20261128_011_notification_dedupe.php`（新增）
- `tests/concurrency/order-idempotency.php`（新增）
- `tests/concurrency/notification-dedup.php`（新增）
- `tests/run.php`（3 项 P6 测试 + 既有第二单用例调整）

## 修改函数
- `AiOrder::create()`：新增第 5 个可选参数 `?string $idempotencyKey = null`；未传时按 `memorialId|userId|serviceSlug|inputJson` 生成 sha256；捕获 SQLSTATE 23000/MySQL 1062 后回查既有订单 ID，重复请求返回同一 ID。
- `Admin\AiOrderController::confirm()`：保留 `Db::transaction` + `SELECT ... FOR UPDATE`；创建 AiJob 后用条件更新 `id=? AND status='pending'`，受影响行数为 0 时抛 `AiOrderConfirmConflict` 回滚；把外部 `AiStudioService::process()` 移出事务，避免长事务。
- `Admin\AiOrderController::reject()`：改为条件更新，防止与 confirm 竞态。
- `NotificationService::emit()`：新增第 8 个可选参数 `?string $dedupeKey = null`；写 `hash('sha256',$key)` 到 `dedupe_key`，唯一键冲突时回查既有通知 ID；未传 key 时行为不变。
- `AiWorkflowService` AI 完成通知传入 `ai_workflow_completed:<workflowId>` 去重键，消除 link 预检竞态导致的重复通知。

## 数据库迁移
- `20261128_010`：为 `ai_orders` 增加 `idempotency_key CHAR(64)` + 唯一键。
- `20261128_011`：为 `notifications` 增加 `dedupe_key CHAR(64)` + 唯一键。
- 均为追加型迁移，不修改历史迁移；`--status` 显示 38 个版本全部 applied，重复执行 rc=0。

## API 变化
无。所有新增参数均为可选尾参，响应结构不变。

## 安全影响
- 订单重复提交不再产生新订单；确认与驳回并发时只有一个成功，另一个走既有关联冲突回退。
- 外部 AI 调用不再持有数据库事务，降低锁等待与不一致风险。
- AI 完成通知在并发完成下只产生一条站内通知。

## 测试
- 新增 3 项：`P6：订单幂等键与通知去重列已迁移`、`P6：10 个并发请求用同一幂等键创建订单只产生一条订单`、`P6：10 个并发请求用同一去重键只产生一条站内通知`。
- 既有第二单用例增加 `caption` 参数以产生不同幂等键（原用例依赖重复下单生成不同 ID，现由幂等键语义替代）。
- `bash bin/test.sh` rc=0，**165 通过 / 0 失败**，日志 `/tmp/opencode/v2.1.1/phase6/integration.log`。
- 并发测试使用 `proc_open` 启动 10 个独立 PHP 进程，非顺序模拟。

## 门禁
PHP lint rc=0；PHPStan Level 5 `[OK] No errors` rc=0；secret-scan rc=0；node --check rc=0；migration status/repeat rc=0；doctor rc=0（0 阻断，4 警告）；`git diff --check` rc=0。

## 未解决问题
- 订单 `cancelled` 状态仍无业务写入路径（历史 Enum 保留，未新增加退订流程）。
- 通知去重仅在显式传 key 的调用点生效，其他通知类型保持原行为。
- 备份加密/异地与恢复演练属于 Phase 7。

## 下一阶段
Phase 7：备份加密、完整性验证与恢复演练闭环。
