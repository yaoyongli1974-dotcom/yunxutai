# v2.1.1 Phase 0 — 数据库、事务、备份与可运维性审计

基线：`e723d07`。只读审查。标注：**CONFIRMED** / **INFERENCE**。

## 1. 结构与迁移（CONFIRMED）

- 32 个迁移文件（`20261127_001…027` 27 个 + `20261128_001…005` 5 个）；`database/schema.sql` 56 个 `CREATE TABLE`。
- `schema.sql` **有意省略**迁移新增列；`bin/install.php:105-112` 在基础 DDL 后执行 `migrate-v2.php`（依据见 `docs/development/v2.1.0/23_DATABASE_REPORT.md:15-24`）。`bin/migrate-v2.php:83-88` 校验已应用版本 SHA-256，`:28-61` 提供 `--status`。
- AI 相关：`ai_workflow_steps` 含 `step_no/status/input_ref/output_ref/started_at/finished_at`（`schema.sql:759-775`，唯一 `(workflow_id,step_no)`）；`ai_workflows` 含状态/重试/租约字段（`:732-745`），`lock_version/heartbeat_at/last_error_at` 仅在迁移 `027:9-11`。
- **无持久化 deadline/due_at 字段，无状态转换历史表**（仅 `archive_versions` 是文件版本史，`schema.sql:710-725`）。`bin/ai-workflow-worker.php:30` 的 `$deadline` 是 Worker 运行时上限，不是工作流截止时间。
- 文件哈希：`archive_files.sha256` 存在（`schema.sql:664`），`storage_key` 唯一（`:669`）；`media` 无哈希列（`:204-221`）。备份产物哈希仅在 manifest（`bin/backup.php:103-106`）。
- 订单：`ai_orders` 有 `status/payment_ref/task_id/paid_at/reviewed_by`（`schema.sql:268-292`），**无幂等键/唯一约束**（仅 3 个普通索引 `:285-287`）；创建是裸 insert（`AiOrder.php:16-24`）。通知表同样无去重键（`schema.sql:953-955`）。
- 删除队列：`archive_cleanup_queue` 仅存在于迁移 `20261128_003:8-22`（唯一 `(archive_id,storage_key)`、索引 `(status,attempts)`），`schema.sql` 中不可见。
- 性能索引 `20261128_005` 的两条索引同样仅存在于迁移；`schema.sql` 仍是旧形状。

## 2. 事务与锁（CONFIRMED）

全库 22 处 `Db::transaction`：AI 工作流创建/领取/结果提交（`AiWorkflowService.php:55,124,312`）、家族时间线、人物合并、纪念馆/机构、归档版本/上传/审批、家族创建/邀请/接受、微信登录、订单确认、审核状态与归档领取（`bin/archive-cleanup.php:36`）。

`FOR UPDATE` 共 4 处：`AiWorkflowService:130`（`FOR UPDATE SKIP LOCKED` 领取）、`ArchiveService:164`（版本）、`AiOrderController:54`（订单确认）、`archive-cleanup.php:42`（队列领取）。

条件更新（CAS）较完整：`AiWorkflowService:140-148/169-175/203-275/325-335/378-386/420-424`、`AiJob:82-88`、`NotificationService:95/100/118-123`、`ArchiveService:457-460/479-482`、`bin/archive-cleanup.php:47-52`、`MysqlCounter:29/38-45`、`FamilyService:220-225`。

### 未包在事务中的多表写入（CONFIRMED / INFERENCE）

- AI 失败路径 `AiWorkflowService.php:378-395`：步骤、工作流、`last_error_at` 与审计不在同一事务；consent 撤销/租约恢复（`:203-275`）亦是行更新 + 审计无事务（CONFIRMED）。
- 通知发送 `NotificationService.php:37-50`：站内信、邮件与审计有意独立（注释 `:11,19`）；AI 完成通知去重是非原子 `COUNT` 预检（`AiWorkflowService.php:350-354`）（CONFIRMED）。
- 订单拒绝 `AiOrderController.php:130-138`：状态更新 + 审计无事务；确认虽在事务内，但把外部 AI 处理放在事务里（`:85-91`），持锁时间长（INFERENCE 风险）。
- 归档清理 `bin/archive-cleanup.php:102-110`：文件删除 + DB 删除 + 队列完成 + 审计不能共享事务，靠缺文件容忍与共享 key 检查保证幂等（CONFIRMED，设计取舍）。
- 邀请接受 `FamilyService.php:181-195`：资格读取在事务前，成员 insert 依赖唯一键，邀请 CAS 正确（CONFIRMED）。
- 角色变更均为单行更新 + 审计无事务（CONFIRMED；INFERENCE：部分失败可能丢审计）。
- 纪念馆邀请创建 `Manage/MemberController.php:53-80` 无事务包裹邀请 insert + 发信（CONFIRMED）。

## 3. 幂等约束（CONFIRMED）

已存在：工作流唯一 `(family_id,workflow_type,idempotency_key)`、步骤唯一 `(workflow_id,step_no)`、`api_tokens.token_hash`、`family_invitations.token_hash`、纪念馆 `invitations.token` + 迁移 `token_hash`、清理队列唯一 `(archive_id,storage_key)`、`notification_preferences` 唯一 `(user_id,type)`。
缺口：`ai_orders` 无唯一/幂等键；`notifications` 无去重键；纪念馆邀请仍保留明文 token 回退；`family_invitations` 无 `(family_id,email,pending)` 唯一（靠 revoke-then-insert 事务保证）；队列列在 schema-only 安装中不可见。

## 4. 备份 / 恢复 / Doctor（CONFIRMED）

- `bin/backup.php`：`mysqldump --single-transaction --quick --skip-lock-tables`（`:64-75`）、gzip `-9 -n`（`:79`）、tar uploads（`:85`）与 verifications（`:93`）、manifest 含 `files.sha256/bytes`（`:110-116`）、`--keep` 默认 14、末尾 `SELECT 1`（`:135`）。**无加密、无异地传输、无 migration 版本/行数/schema hash、不备份 .env 与日志**。
- `bin/restore-check.php`：校验 manifest 各项 sha256/bytes（`:48-92`）、拒绝非法产物名（`:64-66`）、解压统计 `CREATE TABLE/INSERT INTO`（`:117-130`）、支持 legacy 单 gz（`:103-107`），明确“不导入、不等于恢复成功”（`:5-7,27`）。**无自动恢复演练**；演练是 `docs/deployment.md:157-176` 的人工清单。
- `bin/doctor.php`：环境/扩展/安全（`:44-113`）、21 张必需表（`:119-135`）、失败迁移门禁（`:137-142`）、上传限制（`:161-184`）、Redis（`:221-258`）、AI 队列健康（`:276-285`）、Worker 日志 mtime（`:291-296`）；警告不阻断（`:303-315`）。**无备份新鲜度/完整性、无 EXPLAIN/慢查询检查**。
- `bin/archive-cleanup.php`：支持 `--once/--limit/--max-attempts`，事务领取、共享文件保护、attempts 计数，无调度注册（`:13`）。
- `bin/ai-workflow-worker.php`：fencing token、SIGTERM/SIGINT、60s SIGALRM 心跳、max-runtime、结构化日志无输入/凭据（`:25-62,64-121,128`）；**无持久化的每任务 provider deadline**（参见 `docs/development/v2.1.0/93_UNRESOLVED_RISKS.md:10`）。

## 5. 索引与剩余查询风险（CONFIRMED / INFERENCE）

已存在的相关索引：`idx_ai_workflow_status(status,run_after)`、`idx_ai_workflow_family`、`idx_cleanup_status(status,attempts)`、`idx_notification_user`、`idx_archive_family`，以及迁移 005 的两条索引。

仍存在的 N+1 / 无界读取候选（CONFIRMED 代码事实，性能影响为 INFERENCE）：
- `ArchiveController.php:91-96` 循环最多 200 人，逐个 `FamilyPersonService::getVisible`。
- `TimelineController.php:29-31` 每个事件一次 `persons()`（事件上限 500）。
- `ExhibitionService.php:121-128` + `itemLabel`（`:224-234`）逐项查询，发布时逐项检查（`:139-143`）。
- `AiWorkflowService::recoverExpired` 无界选出所有过期/失败任务（`:195-198,246-248`），每行查 `max_retries`（`:221`）；claim 内逐行步骤/版本读取（`:135-152`，上限 50）。
- `FamilyPersonService::visibleRows` 已批量化家族查询（`:91-96`），但逐家族调用 `Policy::familyRole`（`:99-101`）。
- `Memorial::indexable()` 上限 5000（`Memorial.php:155-158`），sitemap 全量输出（`HomeController.php:56`）。

**INFERENCE**：上述均为 Phase 9 性能验证候选；当前无真实数据量压测证据（参见 `docs/development/v2.1.0/32_PERFORMANCE_REPORT.md:38`）。

## 6. 结论

数据库基础约束与迁移校验机制完整，但**订单/通知缺幂等键、AI 失败与恢复链路缺事务边界、备份无加密与自动恢复演练**，且缺少持久化状态历史与截止时间字段。这些问题分别进入 Phase 1/2/6/7 处理。本阶段未修改代码或数据库。
