# v2.1.1 P0 安全加固最终报告（Phase 0–8 汇总）

分支：`feature/v2.1.1-production-hardening`　基线：`e723d07`（Phase 0 `942a6e1`）　日期：2026-11-28
范围：**仅文档**。本文件不修改任何代码、配置、测试，不提交、不推送。

标注约定：
- **CONFIRMED**：有阶段报告中的代码/测试证据；本 lane 未重跑前序测试，数字均为对应报告的记录值。
- **CONFIRMED CLOSED**：风险已由对应阶段修复并有报告证据。
- **OPEN**：未实现、未验证或需运维/真机完成，不能以「已完成」表述。
- **PARTIAL**：部分完成，残余部分仍 OPEN。

证据来源：Phase 0 基线 `00_BASELINE_REPORT.md`、`01_CURRENT_ARCHITECTURE.md`、`02_AI_WORKFLOW_ANALYSIS.md`、`03_SECURITY_AND_PERMISSION_AUDIT.md`、`04_DATABASE_AND_TRANSACTION_AUDIT.md`、`05_TEST_BASELINE.md`、`06_REFACTORING_PLAN.md`、`MODERATION_CONTROLLER_ANALYSIS.md`、`RESOURCE_PERMISSION_MATRIX.md`；实施报告 `10_…` 至 `16_…`、`BACKUP_RESTORE_DRILL.md`；上游风险台账 `docs/development/v2.1.0/93_UNRESOLVED_RISKS.md`。

---

## 1. P0 安全成果总览（Phase 1–8 实际落地）

| Phase | 提交 | 报告 | 核心 P0 成果 | 测试记录 | 标注 |
|---|---|---|---|---|---|
| 1 | `15a610c` | `10_AI_WORKFLOW_STATE_MACHINE_REPORT.md` | AI 步骤**真实状态机**与转换历史 | 150/0 | CONFIRMED |
| 2 | `ff997ae` | `11_WORKFLOW_TIMEOUT_AND_CANCEL_REPORT.md` | 全局截止时间、步骤超时、取消与 fencing | 154/0 | CONFIRMED |
| 3 | `729e9ae` | `12_AI_FACT_CREDIBILITY_REPORT.md` | 事实来源/人工审核可信度 | 157/0 | CONFIRMED |
| 4 | `1ce606b` | `13_ARCHIVE_FILE_CREDIBILITY_REPORT.md` | 归档原始/处理**双哈希**与处理溯源 | 160/0 | CONFIRMED |
| 5 | `18d168f` | `14_RESOURCE_PERMISSION_FIX_REPORT.md` | 口述会话 IDOR / 导出越权修复 | 162/0 | CONFIRMED |
| 6 | `575c4d3` | `15_ORDER_QUEUE_CONSISTENCY_REPORT.md` | 订单/通知幂等与并发一致性 | 165/0 | CONFIRMED |
| 7 | `c92e97c` | `BACKUP_RESTORE_DRILL.md` | 备份 AES-256-CBC 加密与隔离恢复演练 | 166/0 | CONFIRMED |
| 8 | `1a6bbf8` | `16_MODERATION_AND_TEST_MODULARIZATION_REPORT.md` | ModerationController 结构治理 + 测试 helper 外置 | 166/0 | CONFIRMED |

所有阶段报告均记录 `php -l` / PHPStan Level 5 `[OK] No errors` / secret-scan / `node --check` / 迁移全 applied / doctor 0 阻断 / `git diff --check` rc=0。本 lane 未重新执行这些门禁，仅引用记录。

### 1.1 真实 AI 步骤状态机（Phase 1，报告 10）
- `AiWorkflowService::process()` 不再批量把所有步骤置 completed；按 `TYPES` 中声明的真实步骤逐步执行，记录 `started_at/finished_at/step_attempt/execution_id`，模型/OCR 只在需要产出时调用一次，成功时 `review_draft` 进入 `awaiting_review`。
- 新增 `recordStepTransition()` 写 `ai_workflow_step_transitions`；`reviewStep()` 需 `ai.result.review`、FOR UPDATE + CAS，重复审核 409；`cancel()` 把 queued/failed/processing/awaiting_review 全部转 cancelled 并写历史。
- 迁移 `20261128_006` 扩展步骤状态枚举并建转换表；所有步骤更新/状态历史/工作流完成/生成日志在同一 `Db::transaction` 内以条件更新提交，并由 `owns()` 校验 worker/lock_version/lease（防旧 Worker 写回）。
- 结论：R-10 对应的「步骤状态造假」已修复，CONFIRMED CLOSED。

### 1.2 超时、取消与 fencing（Phase 2，报告 11）
- 新增 `workflowTimeout/stepTimeout/leaseSeconds/maxRetries/retryBackoff` 配置读取（带上下限钳制）；`create()` 写 `deadline_at` 与超时秒数；`claimStrict()` 的 SELECT 与条件 UPDATE 都排除 `deadline_at <= NOW()`；`heartbeat()` 用配置租约。
- `recoverExpired()` 对过期任务取消并写 `failure_class='workflow_timeout'` + 审计；`process()` 执行前/每步前重新核对任务状态，取消/超时/预算耗尽立即停止，超时/取消强制不重试；Worker `--max-runtime` 默认取工作流超时，SIGALRM 续租间隔为租约 1/3。
- 迁移 `20261128_007` 增加截止时间字段与索引；状态 API 返回真实 `deadline_at`。
- 结论：R-11 对应的「无全局截止时间/内部超时」已修复，CONFIRMED CLOSED。**残余 OPEN**：步骤级超时是执行预算 + 完成后校验，真正的模型调用中断依赖 `ai.provider_timeout_seconds` 与租约，不实现多线程硬 kill；工作流级独立状态历史表未建（复用审计+步骤历史）。

### 1.3 事实来源与人工审核（Phase 3，报告 12）
- 迁移 `20261128_008`：`memory_facts` 增加 `fact_kind`（含 `ai_inferred`）、`workflow_id`、`review_status`、`review_note`、`version`，新建 `ai_fact_revisions` 保存审核前快照。
- `addFactCandidate()` 校验来源档案/版本/workflow 归属，按来源映射 fact_kind，AI 提取默认 `ai_inferred`、置信度 <1 并写 `source_location`、`review_status=pending`；`reviewFact()` 支持多状态映射、consent 撤回后拒绝审核、同事务 CAS + revision；`syncWorkflowReview()` 在无待审核事实时推进工作流审核步骤；`facts()` 过滤 consent 已撤回工作流。
- Web/小程序展示来源与审核状态标签，明确「AI 候选（非已核实事实）」。CONFIRMED。

### 1.4 归档双哈希与处理溯源（Phase 4，报告 13）
- 迁移 `20261128_009`：`archive_files` 追加原始/处理哈希、大小、处理方式/库/参数、时间戳、`original_kept/verified`；新建 `archive_file_processing` 溯源表。
- `Storage::storeArchiveUpload()` 尽早计算 `original_sha256/original_size`，JPEG GD 解码成功后保留原始字节副本再以 quality=88 重编码算 `processed_sha256`；新增 GIF 真实解码校验、JPEG 解码失败直接拒绝；失败时清除主文件/原始副本/DB 记录。
- `bin/archive-cleanup.php` 同时删除原始副本并写 `original_removed` 审计；Web 仅展示处理后 SHA-256，文案改为「入库文件 SHA-256」，不再把派生哈希误称为原始哈希。
- 结论：R-20（哈希为重编码副本、未保留原始字节）CONFIRMED CLOSED。**残余 OPEN**：多处理版本链未建、未引入病毒扫描、`media` 表仍无哈希字段。

### 1.5 资源级权限：口述会话 IDOR（Phase 5，报告 14）
- `FamilyV2Controller` 新增 `assertOralMember()` / `assertOralExport()` / `oralSessionForMember()`；`oralSessions()`、`oralSession()`、`sessionSegments()` 先按路径 `family_id` 与会话归属复核 active 成员身份；`exportSession()` 先解析归属家族再要求 `data.export`（owner/admin）；拒绝统一 404，不泄露存在性。
- 2 项 P5 回归测试覆盖跨家族读取三端点 404、public 家族非成员导出 404 / owner 200。Phase 0 `03_SECURITY_AND_PERMISSION_AUDIT.md` 与 `RESOURCE_PERMISSION_MATRIX.md` 中标记的 P0 越权已消除，CONFIRMED CLOSED。
- **残余 OPEN**：未建模独立 `oral_history.view` 能力；API 仍无 `api-admin` 路由（审核/数据请求 Web-only）；分享链接受 P0 范围外；`Admin/UserController::updateStatus()` 未清 `Policy::$roles` 的缓存问题未见 v2.1.1 报告声称修复。

### 1.6 订单与通知幂等（Phase 6，报告 15）
- `AiOrder::create()` 新增可选幂等键（缺省 `memorialId|userId|serviceSlug|inputJson` 的 sha256），捕获 1062/23000 后回查同一订单 ID；`confirm()` 保留事务 + FOR UPDATE，创建 job 后条件更新，0 行则抛冲突回滚，外部 `AiStudioService::process()` 移出事务；`reject()` 同样改条件更新。
- `NotificationService::emit()` 新增可选去重键，唯一键冲突回查既有通知；AI 完成通知传 `ai_workflow_completed:<workflowId>`，消除 link 预检竞态。
- 迁移 `20261128_010/011` 增加唯一列；新增 `tests/concurrency/order-idempotency.php`、`notification-dedup.php`（`proc_open` 10 个独立进程），3 项 P6 测试。CONFIRMED。
- **残余 OPEN**：订单 `cancelled` 状态无业务写入路径；通知去重仅在显式传键的调用点生效；10 路管理员会话 + CSRF 的 HTTP 并发 `confirm()` 仍未自动化（v2.1.0 §2 遗留）。

### 1.7 加密备份与恢复演练（Phase 7，`BACKUP_RESTORE_DRILL.md`）
- `bin/backup.php` 支持 `BACKUP_ENCRYPTION_KEY`（≥16 字符），`hash('sha256', key, true)` 派生 AES-256 密钥，逐产物 16 字节随机 IV，`aes-256-cbc` 生成 `*.enc`（0600）并删明文；manifest 记录明文 sha256/bytes 与加密元数据、算法与 `key_fingerprint`，**密钥本体不落盘/不入库**。
- `bin/restore-check.php` 要求同密钥且指纹匹配，先验密文 sha256/bytes，再解密校验明文与 SQL 内容；错误密钥/缺失密钥/篡改均 exit 1。`bin/doctor.php` 加非阻断提示。
- 隔离演练（记录于 `/tmp/opencode/v2.1.1/phase7/drill/`）：备份 → 正确密钥 rc=0 / 错误密钥 rc=1 / 篡改 rc=1 → openssl 解密 → 导入隔离库 `jidian_restore` → 行数核对（users 8/8、memorials 5/5、migrations 27/27）→ doctor rc=0 → 删隔离库。**未触碰生产数据库**。CONFIRMED。
- **残余 OPEN**：脚本不自动异地上传；RPO 上限 24 小时、未量化到分钟；RTO 仅在极小数据量下计时，未做生产切换演练；密钥托管流程未建立；备份不含 `.env` 与 `storage/logs`（需运维保管）。

### 1.8 ModerationController 结构治理（Phase 8，报告 16）
- 超大控制器收为薄 façade：13,022 字节/347 行 → 9,129 字节/238 行；7 个公开 action 名称/签名/路由/响应/权限/审计不变；重复批量逻辑抽到 `Moderation/BatchProcessor`、`ModerationQueries`、`IdsParser`；`guard()` 权限与 CSRF 不变，无新增攻击面。
- `tests/Support/Harness.php` 外置 13 个 helper；`grep -c '^test('` 前后均 166，顺序与退出语义不变；166/0 通过。CONFIRMED。
- **残余 OPEN**：166 项测试尚未迁移到 `tests/suites/*`；`parseIds` 中为兼容旧 baseline 保留的无操作 `array_values()`；未做大数据量审核页性能压测。

---

## 2. 风险处置台账（含上游 `93_UNRESOLVED_RISKS.md` 与本阶段报告）

| 来源 | ID | 上游状态 | v2.1.1 处置 / 证据 | 当前标注 |
|---|---|---|---|---|
| 93 | R-10 | OPEN | Phase 1 真实步骤状态机 + 转换历史（报告 10） | **CONFIRMED CLOSED** |
| 93 | R-11 | PARTIAL | Phase 2 截止时间/超时/取消/租约（报告 11）；步骤级硬中断仍依赖 provider timeout | **CONFIRMED CLOSED**（硬中断子项 **OPEN**） |
| 93 | R-16（迁移断言粒度） | PARTIAL | 各阶段只验证迁移全 applied；未见"每条迁移独立断言 + 预期表结构"完成证据 | **OPEN** |
| v2.1.1 06 | R-16（205KB 测试单文件） | 计划项 | Phase 8 外置 13 个 helper；166 用例未迁移 suites（报告 16） | **PARTIAL / OPEN** |
| 93 | R-18 | CLOSED | 无回归证据 | **CONFIRMED CLOSED** |
| 93 | R-20 | PARTIAL | Phase 4 保留原始字节 + 原始哈希 + 溯源表（报告 13） | **CONFIRMED CLOSED** |
| 93 | R-21 | OPEN | `20261027_verification.sql` 未纳入版本化 runner / 幂等性仍待决策，v2.1.1 无相关报告 | **OPEN** |
| 93 | R-22 | CLOSED | 无回归证据 | **CONFIRMED CLOSED** |
| 93 | R-23 | CLOSED | Phase 6 追加 `ai_workflow_completed:<id>` 去重键，进一步消除竞态（报告 15） | **CONFIRMED CLOSED** |
| 93 | R-30 | PARTIAL | `deploy/nginx.conf` 中 Report-Only 仍为**注释示例**；`docs/security.md` 明确应用 enforcing CSP 是唯一生效策略，边缘 Report-Only 未启用、无真实违规遥测 | **OPEN**（Report-Only 未强制/未启用） |
| 93 | N-07 | OPEN | 签名仍绑定可见性 + TTL + no-store，无 per-user 撤销；v2.1.1 未改 | **OPEN** |
| 93 §2 | 远程四矩阵 CI | 本地复验 | v2.1.0 有 4/4 绿记录；**本分支**未取得四矩阵 CI 全绿证据 | **OPEN / NEEDS_VERIFICATION** |
| 93 §2 | 真机/微信开发者工具 | 未走查 | 本报告同目录 `MINIPROGRAM_DEVICE_TEST_REPORT.md` 全部结果「未执行」 | **OPEN** |
| 93 §2 | 大规模性能数据 | 未生成 | v2.1.1 各报告仅记录小数据门禁，无 1000/10000/5000 规模数据集与 EXPLAIN 结果；仅合成数据可用 | **OPEN** |
| 93 §2 | 订单 10 路 HTTP 并发 confirm | 未自动化 | Phase 6 并发测试为 10 进程创建幂等/通知去重，非管理员会话+CSRF 的 confirm | **PARTIAL / OPEN** |
| 93 §2 | fail-closed 与 2FA 故障注入 | 未自动化 | v2.1.1 报告未见存储停机/跨 UA 自动化证据 | **OPEN** |
| 93 §2 / §3 | 生产迁移/部署/配置/发版 | 未执行 | 本分支只在本地与隔离演练库操作 | **CONFIRMED（未执行）** |
| 93 §3 | 备份异地存储 | 未自动化 | Phase 7 明确脚本不自动上传，需运维对象存储/多副本 + 分离密钥 | **OPEN** |
| 93 §3 | 恢复演练范围 | 有限 | 隔离库导入 + doctor + 行数核对，非全业务恢复验收 | **CONFIRMED（有限）** |
| 93 §3 | APP_DEBUG / APP_KEY / APP_TRUSTED_PROXY | 生产待配置 | 默认 fail-closed，生产需显式配置；本分支未改生产配置 | **OPEN** |
| Phase 7 | 密钥托管 | 新缺口 | 密钥丢失即无法恢复，未建立托管流程 | **OPEN** |
| Phase 7 | RPO/RTO 量化 | 新缺口 | RPO 24h、RTO 未真实数据量计时 | **OPEN** |
| Phase 8 | 审核页大数据压测 | 新缺口 | 报告 16 §7 明确 OPEN | **OPEN** |
| Phase 9（06/16） | 生产监控指标与告警 | 仅计划 | 06/16 报告把它列为下一阶段；本分支无监控/告警落地报告 | **OPEN** |
| Phase 9（06/16） | 用户数据生命周期（删除/导出） | 仅计划 | 无 v2.1.1 实施报告 | **OPEN** |
| Phase 9 列 E | 小程序上传/档案详情/取消/分享 | 缺口 | 见 `MINIPROGRAM_DEVICE_TEST_REPORT.md` §1.2、§7 | **OPEN** |

> 标注说明：`CONFIRMED CLOSED` 只表示该阶段**代码与测试证据**支持风险关闭；生产环境是否生效仍取决于部署与运维配置（见上表 OPEN 项）。

---

## 3. 生产事件与数据访问声明

- 本分支**未处理任何生产安全事件**，未收到、未调查、未上报生产安全事故。
- 本分支**未访问生产数据**：Phase 7 恢复演练使用本地生成备份与隔离库 `jidian_restore`，行数核对后已删除；其余阶段使用本地测试库 `jidian_test` 与报告日志。
- 本 lane **未执行**生产迁移、部署、配置变更或发布；未接触生产密钥、token 或用户数据。
- 上述声明为仓库内文档与报告可核实范围内的陈述；生产侧实际操作由运维/发布流程负责，本文不代为确认。

---

## 4. 证据索引

- Phase 0 基线：`00_BASELINE_REPORT.md`、`01_CURRENT_ARCHITECTURE.md`、`02_AI_WORKFLOW_ANALYSIS.md`、`03_SECURITY_AND_PERMISSION_AUDIT.md`、`04_DATABASE_AND_TRANSACTION_AUDIT.md`、`05_TEST_BASELINE.md`、`06_REFACTORING_PLAN.md`、`MODERATION_CONTROLLER_ANALYSIS.md`、`RESOURCE_PERMISSION_MATRIX.md`
- Phase 1–8：`10_AI_WORKFLOW_STATE_MACHINE_REPORT.md`、`11_WORKFLOW_TIMEOUT_AND_CANCEL_REPORT.md`、`12_AI_FACT_CREDIBILITY_REPORT.md`、`13_ARCHIVE_FILE_CREDIBILITY_REPORT.md`、`14_RESOURCE_PERMISSION_FIX_REPORT.md`、`15_ORDER_QUEUE_CONSISTENCY_REPORT.md`、`16_MODERATION_AND_TEST_MODULARIZATION_REPORT.md`、`BACKUP_RESTORE_DRILL.md`
- 上游风险台账：`docs/development/v2.1.0/93_UNRESOLVED_RISKS.md`
- 真机交接：`docs/development/v2.1.1/MINIPROGRAM_DEVICE_TEST_REPORT.md`（本报告同目录）

---

## 5. 校验

- 本 lane 仅写本文件；未改代码、配置、测试或其他文档，未提交、未推送。
- `git diff --check` 的命令、退出码与文件尺寸见交付摘要。
