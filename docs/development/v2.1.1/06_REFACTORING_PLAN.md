# v2.1.1 Phase 0 — 改造实施计划

基线：`e723d07`（行为等价于 v2.1.0 `ff23bc5`）。原则：小步提交、每阶段测试通过后进入下一阶段、不删除既有测试、不降低 PHPStan Level 5、不改历史迁移语义、不直接操作生产数据。

## 1. 已确认问题与对应阶段

| 编号 | 问题 | 证据来源 | 阶段 |
|---|---|---|---|
| R-10 | AI 工作流步骤状态造假：成功时全部批量 `completed`，失败循环 targets `processing` 实际空操作，步骤从未置 `processing` | `02_AI_WORKFLOW_ANALYSIS.md`；`AiWorkflowService.php:316-324,378-382` | Phase 1 |
| R-11 | 无工作流总截止时间/步骤超时配置；租约 900s 硬编码，`process()` 内无取消/超时复核 | `02_AI_WORKFLOW_ANALYSIS.md`；`config/config.php:91-105` | Phase 2 |
| R-P0-01 | 口述会话/片段 API 跨家族越权读 | `03_SECURITY_AND_PERMISSION_AUDIT.md` §3.1；`routes-api.php:83,85,88` | Phase 5 |
| R-P0-02 | 口述会话导出授权不足（public 家族任意认证用户） | 同上 §3.2 | Phase 5 |
| R-P1-03 | 禁用用户后 `Policy::$roles` 缓存滞留 | 同上 §3.3 | Phase 5 |
| R-12 | 原始文件哈希在处理后计算且 JPEG 被重编码，原始/处理哈希未分离 | v2.1.0 遗留风险、`Storage.php:121-138` | Phase 4 |
| R-13 | `ai_orders` 无幂等键；确认在事务内做外部 AI；异常被吞导致 `paid` + `failed` | `04_DATABASE_AND_TRANSACTION_AUDIT.md` §1/§2；`AiOrderController.php:43-109` | Phase 6 |
| R-14 | 备份无加密/异地，恢复演练仅人工清单 | `04_DATABASE_AND_TRANSACTION_AUDIT.md` §4 | Phase 7 |
| R-15 | `ModerationController.php` 曾被巨量行首空白污染（已修复为 13,022 字节 / 347 行），仍需结构与职责分析 | 历史提交 `e723d07`；待 `MODERATION_CONTROLLER_ANALYSIS.md` | Phase 8 |
| R-16 | `tests/run.php` 约 205KB，单文件维护成本高 | 目录统计 | Phase 8 |
| C10 | AI 自由文本输入无长度上限 | `02_AI_WORKFLOW_ANALYSIS.md` | Phase 2/9 |

## 2. 阶段顺序与最小改动

### Phase 1 — AI 工作流真实状态机
- 复用 `ai_workflow_steps` 现有字段（`status/started_at/finished_at/input_ref/output_ref`），仅在确有必要时由新迁移 `20261128_006` 增加 `step_attempt/step_error_code/step_execution_id` 等。
- 将 `process()` 的批量步骤写入改为按步骤驱动：`pending → running → completed|failed|skipped|cancelled`，每次转换带 `lock_version`/`worker_id`/租约校验与事务。
- 新增状态历史（优先审计复用；若现有 `audit_logs` 字段不足则新表）。
- API 返回真实步骤状态与进度；移除前端根据总状态推断全部成功的逻辑。
- 测试：合法/非法转换、fencing、并发同一步、失败保留真实状态、取消不推进、历史与状态事务一致。

### Phase 2 — 全局截止时间、步骤超时与取消
- 新增配置（`AI_WORKFLOW_TIMEOUT_SECONDS` 等）带默认值与上下限校验；持久化 `deadline_at`/步骤开始结束时间。
- 步骤执行前检查取消/过期/租约/版本/重试次数/预算；执行中检查全局截止时间；取消与完成并发只有一个结果。
- 失败分类对齐任务书（provider_timeout/rate_limit/unavailable 等）与重试策略。
- 测试：模型超时、步骤超时、全局超时、取消、租约过期、旧 Worker、Retry-After、最大重试、退避、无重复订单/生成。

### Phase 3 — AI 来源与人工审核
- 复用 `memory_facts.source_type/source_id/source_location/confidence` 与 `20261128_002` 新增列；补齐 `source_location` 与来源归属校验。
- 区分 user_provided/archive_verified/ai_inferred 等；AI 候选默认 `pending`，不得自动 `confirmed`。
- 审核支持 approve/reject/request_revision，保留审核前后内容与意见，撤销授权后禁止继续处理。
- 前端标识用户原文 / AI 辅助 / AI 生成 / 人工审核。

### Phase 4 — 媒体可信度
- 上传后尽早计算 `original_sha256`；归一化/处理后单独计算 `processed_sha256` 并记录处理方式、库版本、参数与时间。
- 新增文件处理版本记录表或复用 `archive_versions`；处理失败不得标记上传成功；保留原始文件与溯源关系。
- 复查上传安全（MIME/签名/尺寸/EXIF/SVG/HTML/路径穿越/执行权限）。

### Phase 5 — 资源级权限与隐私治理
- 修复口述会话 API 越权读与导出授权不足；禁用用户同步清理 `Policy::$roles`。
- 按 `RESOURCE_PERMISSION_MATRIX.md` 逐项补 Web/API/小程序负例测试；分享撤销即时生效。

### Phase 6 — 订单与队列并发一致性
- `ai_orders` 增加幂等键/唯一约束；确认、通知发送、删除任务的幂等与事务边界；外部 AI 调用移出长事务（短事务领取 → 外部调用 → 短事务提交）。
- 新增真实并发测试（订单确认、两个 Worker 领取/完成、完成 vs 取消、重试 vs 旧 Worker、通知去重、删除任务重复）。

### Phase 7 — 备份加密与恢复演练
- 扩展 `bin/backup.php`：加密（密钥独立于备份目录/不入 Git/不入日志）、异地同步钩子、manifest 增加迁移版本/行数/schema hash。
- 扩展 `bin/restore-check.php`：错误密钥/篡改检测；自动化隔离环境恢复演练并产出 `BACKUP_RESTORE_DRILL.md`（含 RPO/RTO）。

### Phase 8 — 代码结构治理
- 基于 `MODERATION_CONTROLLER_ANALYSIS.md` 按真实职责拆分（保持路由/API/权限/事务/审计/前端兼容），不做机械式拆分。
- `tests/run.php` 先建立兼容入口与 `Support/` 助手，再逐步迁移新测试；保持测试数量与退出码不变。

### Phase 9 — 安全与性能
- CSP Report-Only 先观察再 Enforce；数据生命周期与用户删除/导出；小程序真机验证（Android/iOS/开发者工具）；性能数据集与 EXPLAIN；监控指标与告警。
- 未能在当前环境执行的设备测试/大容量压测，必须按“未执行”记录，不得虚报。

### Phase 10 — 最终质量门禁
- 全量测试、四矩阵 CI、安全审查、恢复演练、文档齐备后，才输出发布建议；存在未关闭 P0 时禁止建议发布。

## 3. 阶段门禁要求

每个阶段完成必须提供：修改文件/函数、迁移、API/前端变化、安全影响、测试结果、未解决问题、下一阶段；`git diff --check`、PHP lint、PHPStan Level 5、secret-scan、相关测试 Zero-fail 后方可进入下一阶段。所有迁移必须新增文件、可重复验证、兼容 MySQL 8.4 与 MariaDB 11。

## 4. 当前 Phase 0 交付状态

已完成：`00_BASELINE_REPORT.md`、`01_CURRENT_ARCHITECTURE.md`、`02_AI_WORKFLOW_ANALYSIS.md`、`03_SECURITY_AND_PERMISSION_AUDIT.md`、`04_DATABASE_AND_TRANSACTION_AUDIT.md`、`05_TEST_BASELINE.md`、`06_REFACTORING_PLAN.md`、`RESOURCE_PERMISSION_MATRIX.md`、`MODERATION_CONTROLLER_ANALYSIS.md`。Phase 8 的具体拆分方案已依据 `MODERATION_CONTROLLER_ANALYSIS.md` 细化：保持 7 个公开 action 不变，仅抽离 `BatchProcessor`、`ModerationQueries`、`IdsParser` 三个内部协作者；测试模块化按 27 个既有分节增量迁移并保持 145 项不变。Phase 0 不修改业务代码；提交后等待批准进入 Phase 1。

## 5. Phase 9 执行记录

CSP 采用零运行时变更的注释版 Report-Only 示例，并写入 `docs/security.md` 路线图与 `docs/deployment.md` 回归清单；未采集真实违规遥测，未切换 Enforce。数据生命周期治理写入 `docs/compliance.md` §9，区分 CONFIRMED 与 OPEN。监控日志补齐 `workflow_id/worker_id/error_code`，敏感异常文本以 sha256 记录。新增隔离性能 seeder 与全规模合成数据报告（EXPLAIN/计时见 `PERFORMANCE_TEST_REPORT.md`），防写保护拒绝 `jidian`/`jidian_test`，隔离库已删除。真机 27 项全部标注「未执行」，`SECURITY_FINAL_REPORT.md` 列出 OPEN/PARTIAL 风险。全量门禁：166/0 测试、PHPStan（仅修正 seeder PHPDoc 后）`[OK] No errors`、其余门禁 rc=0。详见 `17_PHASE9_SECURITY_PERFORMANCE_REPORT.md`。
