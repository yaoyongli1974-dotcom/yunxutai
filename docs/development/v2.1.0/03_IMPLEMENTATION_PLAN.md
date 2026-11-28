# 云叙台 v2.1.0 — 实施计划（Phase 0 输出，待人工批准）

基线：`49fc14e94adeadcf4476873f8e19413ac16a30f9`
分支策略：在 `feature/v2.1.0-production-hardening` 上逐阶段提交。**未经明确授权不 push main、不发 Release、不动生产。**
版本策略：目标 v2.1.0 为候选；若最终未引入破坏性/不兼容变更且 P0/P1 全闭环，再由人工决定是否升级；否则可降为 v2.0.2。

执行顺序：Phase B（P0 安全）→ Phase C（P1 数据与任务可靠性）→ Phase D（P2 体验与性能）→ Phase E（最终回归与发布准备）。每阶段完成后先跑对应测试、写阶段报告，再进入下一阶段。

---

## Phase A（本阶段，已完成）

- 只读基线审查、四份文档产出（00/01/02/03）。
- 门禁：不得进入 Phase B，直到项目负责人确认本计划、风险登记册及 `00_BASELINE_AUDIT.md` §10 的需人工决策项。

---

## Phase B：P0 安全修复

| 任务 | 范围与最小改动 | 测试 | 验收标准 | 回滚 |
|---|---|---|---|---|
| B1 媒体签名安全（R-01、N-07） | 在 `app/bootstrap.php` 启动时校验 `APP_KEY` 缺失/等于开发占位符则 fail-closed；`app/Api/Controllers/MediaController.php::show()` 增加 `Policy::canViewMedia()` 复核；`MediaSign` 绑定可见性版本或缩短 TTL | 新增：同意外伪造签名、签名签发后降级/撤销重放；保留现有有效/篡改/过期签名测试（`tests/run.php:1269-1284`） | 伪造 403/404；撤销后签名立即失效 | 仅恢复环境变量与策略调用；无数据变更 |
| B2 邀请链接收口（R-02） | 纪念馆邀请 token 改存 `hash('sha256',$token)`，接受时校验登录用户 email 与 invitation email；新增迁移兼容存量 token（双验证窗口）；禁止空 email 邀请 | 新增：A 持 B 的 token 接受被拒、明文 token 不再入库、空 email 邀请被拒；保留 `tests/run.php:1959,2202,2320,2244` | 跨用户/跨家族接受失败 403/404 | 迁移可回滚列；保留旧 token 过渡期 |
| B3 改密吊销 Token（R-12） | `AccountController::updatePassword()` 成功后 bulk revoke 该用户 `api_tokens` | 新增：改密后旧 Bearer 401、新登录 Token 200 | 旧 Token 立即失效 | 用户重新登录即可，无需数据回滚 |
| B4 登录限流（R-13） | `AuthController::login()` 对每次凭证尝试都命中 IP 维度 key，账号维度逻辑保留 | 新增：单 IP 对 6 个账号各 5 次尝试后 IP 被封 | 喷洒触发 30/3600 限制 | 调整计数逻辑即可 |
| B5 私有媒体缓存（R-19） | `Controllers/MediaController.php`、`Api/Controllers/MediaController.php` 改 `Cache-Control: no-store, must-revalidate` | 新增：响应头断言；保留现有媒体可见性测试 | 私有媒体不缓存 | 头字段回退 |
| B6 AI 同意实时生效（R-03） | `AiWorkflowService` 在 `run()` 外部调用前复核家族/档案可见性与会话同意；`revokeConsent()` 覆盖 `queued|processing|failed`；`recoverExpired()` 不得重入队已撤销任务 | 新增：创建→领取→撤销→process 断言无外部调用；撤销后 recover+claim 不得执行；保留 `tests/run.php:2651-2678,3183-3245` | 撤销后立即且持续阻断外部传输 | 只改状态校验；已生成事实保留并标记来源 |
| B7 缓存与代理安全（N-01~N-04，先验证后修） | 验证后：角色/状态变更处调用 `Policy::clearCache()`、`Auth` 缓存失效；`secure` 改为信任可信代理配置；`RateLimiter::blocked()` fail-closed；2FA pending 绑定 UA/IP | 每项新增针对性测试（见 N-01~N-04 验证方法） | 验证为真的项才有修复；验证为假则关闭风险 | 均为配置/校验层改动 |

**Phase B 门禁**：B1–B6 必须全部通过；N-01~N-04 未验证项须给出验证结论，不能默认通过；产出 `10_SECURITY_FIX_REPORT.md`、`11_AUTHORIZATION_TEST_REPORT.md`、`12_MEDIA_SECURITY_REPORT.md`。

---

## Phase C：P1 数据与任务可靠性

| 任务 | 范围与最小改动 | 测试 | 验收标准 | 回滚 |
|---|---|---|---|---|
| C1 schema/迁移漂移（R-05） | 将 027 三列回写 `database/schema.sql:728-757`；`bin/install.php` 安装后执行 `migrate-v2.php`（或等价兼容处理）；不改已发布迁移语义 | 全新 `install.php --fresh` 后 `SHOW COLUMNS` 含三列并调用 `claim()`；双引擎实跑 | 空库安装即可运行 AI 工作流 | 只改 schema/install，已应用库无操作 |
| C2 Web 运行 fencing（R-06、R-07） | `AiWorkshopController` 传 `lockVersion`；`AiWorkflowService::process()` 无版本时拒绝或自行严格领取；`heartbeat()` 去掉 `owns()` 回退，`owns()` 加租约过期判断 | 新增：过期 Worker/Web 并发写被拒；心跳回退测试；保留 `tests/run.php:2633-2649,3054-3103` | 只有持有效租约版本者可写结果 | 失败任务保持可重试状态 |
| C3 创建幂等（R-08） | 捕获唯一键冲突并返回既有 workflow id；无 idempotency key 时由服务端生成稳定 key | 并发同 key 创建测试 | 返回单一 id，无 500 | 无数据回滚 |
| C4 订单确认（R-09） | `Admin/AiOrderController::confirm()` 加事务 + `UPDATE ... WHERE status='pending'` 条件更新；必要时增加唯一键迁移 | 10 并发确认测试 | 仅生成一个 ai_job，重复返回冲突 | 迁移可回滚；重复订单保持 pending |
| C5 步骤状态（R-10） | `process()` 逐步设置 `processing`→`completed/failed`，失败步骤保留真实进度 | 中途失败测试，检查步骤状态 | 步骤反映实际执行 | 兼容旧数据 |
| C6 重试分类/超时（R-11） | 使用 `ProcessingException::isRetryable()` 区分 `input_error/auth_error/provider_error`；`process()` 增加总截止时间与 provider timeout 配置 | 注入不可重试异常断言不重试 | 永久错误不重试、超时可控 | 配置回退 |
| C7 归档删除物理清理（R-04，需人工决策） | 二选一：批准即清理，或写入待清理表由幂等任务清理；二者均不得删除共享文件 | 新增：删除后文件/行不存在或待清理状态可追踪；重复清理幂等 | 无残留可恢复私有文件 | 队列方案可回滚；即时删除不可逆需备份 |
| C8 杂项修复（R-18、R-20、R-21） | `catch (\Throwable)`；哈希改为重编码前计算并修正文案；`20261027_verification.sql` 纳入独立迁移或明确一次性说明 | PHP lint + 单测 + 迁移双引擎 | 无孤儿文件、哈希语义正确、迁移路径明确 | 均为局部改动 |
| C9 门禁补齐（R-15、R-16、R-23） | doctor 增加 v2 表/迁移状态检查；迁移断言覆盖 `001-027`；AI 完成事件发 `emit()` 并去重 | doctor 缺表/失败迁移测试；迁移全量断言；通知幂等测试 | CI 不再放过缺表/漏迁移 | 检查项可临时豁免但不得删除 |
| C10 AI 来源与输入边界（N-08、N-09 + 来源追踪） | `addFactCandidate()` 写 `source_location`；自由文本 `text` 增加上限；公开家族草稿可见性按策略收紧 | 大输入测试、公开家族草稿越权测试、来源字段断言 | 事实可溯源、草稿不外泄 | 只增字段/校验，不改已确认事实 |

**Phase C 门禁**：新增独立 Migration（预计 1–3 个，具体编号按现有 027 之后顺延）；MySQL 8.4 与 MariaDB 11 双引擎通过；产出 `20_AI_WORKFLOW_REPORT.md`、`21_DATA_CONSISTENCY_REPORT.md`、`22_DATA_GOVERNANCE_REPORT.md`、`23_DATABASE_REPORT.md`、`24_AUDIT_REPORT.md`。

---

## Phase D：P2 体验、小程序与性能

| 任务 | 范围与最小改动 | 测试 | 验收 | 回滚 |
|---|---|---|---|---|
| D1 请求追踪（R-24） | `bootstrap.php` 生成/接收 `X-Request-Id`；新迁移加字段+索引；`Audit::log()`、API 错误信封、`error-handler.php`、响应头携带 | 制造错误并检查四端可关联 | request_id 贯穿成功/失败/Worker | 字段可空，旧记录兼容 |
| D2 调试护栏（R-25、N-12、N-13） | `config/config.php` 默认 `APP_DEBUG=false`；生产环境 + debug 即 fail-fast；debug 视图/payload 仅本地；错误日志脱敏 | staging 强制 500/404 断言无 trace/SQL/路径 | 生产配置无法泄漏堆栈 | 配置层回退 |
| D3 审计归因与脱敏（R-26） | `Audit::log()` 显式 `actorId` 支持 Bearer；API Token 签发写审计；meta 增邮箱哈希/姓名脱敏/异常清洗/设置白名单 | 新增：API 操作审计 actor 非空、meta 无敏感原文 | 审计可归因且不泄漏隐私 | 只改写入路径，旧日志不变 |
| D4 批量上传与进度（R-27） | 在现有单文件端点上加 `multiple` + 前端小队列（每文件进度/失败列表），复用 `Storage` 校验 | 多文件上传与部分失败测试 | 可批量、失败可见、无绕过校验 | 前端回退为单文件 |
| D5 新手引导（R-28） | Dashboard 增加紧凑步骤清单（创建→验证→邀请→发布），复用 `partials/empty-state.php`，进度持久化 | 首次/跳过/继续流程测试 | 可跳过、不重复、移动端可用 | 视图层回退 |
| D6 小程序补齐（R-29、N-14、N-15） | `onLaunch` 接线 `ensureSession()`；统一 401/403 处理与重试策略（有副作用请求不重试）；补充缺失页面或明确 Web-only 范围 | 12 页过期 Token/越权模拟；`node --check` | Token 过期无状态丢失，越权有明确提示 | 回退原 401 reLaunch |
| D7 移动端与谱系（N-16） | 真机检查横向溢出/触控；谱系图补双指缩放或说明限制 | 真机多尺寸测试 | 无溢出、关键操作可触达 | CSS/JS 局部回退 |
| D8 性能基线 | 合成数据（1000 人物/10000 关系等）记录查询数、响应时间、执行计划，不盲加索引 | 可重复脚本 + 报告 | 有基线数据与计划，无 N+1 明显退化 | 不改业务逻辑 |
| D9 边缘安全头（R-30） | 盘点资源后补 Nginx CSP，保持应用与边缘一致；不启用会阻断业务的严格策略 | 头部断言 + 页面回归 | 静态/动态资源均受保护且页面正常 | Nginx 配置回退 |

产出 `30_USER_EXPERIENCE_REPORT.md`、`31_MINIPROGRAM_REPORT.md`、`32_PERFORMANCE_REPORT.md`、`33_DEPLOYMENT_CHECKLIST.md`。

---

## Phase E：最终回归与发布准备

| 任务 | 范围 | 测试/证据 | 验收 |
|---|---|---|---|
| E1 备份可验证（R-14、R-17、N-11） | `bin/backup.php` manifest 加 sha256；新增隔离环境恢复演练脚本（不连生产） | 导入备份→比对行数/文件→启动应用→验证媒体鉴权；禁止仅凭 `restore-check` 返回 0 | 真实恢复演练成功，输出 `05_BACKUP_RECOVERY_REPORT.md` |
| E2 测试脚本加固（R-22） | `bin/test.sh` 服务探测失败即退出；`TEST_DB` 限定 `jidian_test` 前缀/白名单 | 故意错配 DB 名断言脚本拒绝 | 无误报成功、无误删风险 |
| E3 全量回归 | 按 CI 顺序：PHP lint、composer validate/install、PHPStan level 5、gitleaks、secret-scan、JS check、128+ 新增测试、migration status/repeat/checksum、doctor | 本地日志 + 远程 4 矩阵 CI run | 全部 rc=0，注解 0，无跳过 |
| E4 版本与报告 | 生成 `90_FINAL_TEST_REPORT.md`、`91_RELEASE_READINESS_REPORT.md`、`92_ROLLBACK_PLAN.md`、`93_UNRESOLVED_RISKS.md`、`00_MASTER_ACCEPTANCE_REPORT.md`；逐项回答任务书第十五部分 17 个问题 | 报告交叉核对代码/测试/CI | 数据具体、无“基本完成”等模糊表述 |
| E5 发布门禁 | 仅在人工授权后 push main / 打 tag / 创建 Release；不执行生产迁移与部署 | `git status` 干净、CI run URL、Release URL | 未授权前保持分支状态 |

---

## 全局约束与暂停条件

**必须暂停并报告**：发现可能导致生产数据丢失的修改；不可逆数据结构变更；现有 API 不兼容变化；需要删除用户数据；需要改生产配置；业务规则无法确定；需要人工授权的隐私处理；与现有功能冲突的设计。

**禁止（除非另行授权）**：push main、强制推送、创建 Release、修改生产服务器/数据库/Nginx/DNS/AI 真实密钥/支付配置、关闭线上服务、删除或降级测试/门禁、缩小 CI 矩阵、伪造结果。

**回滚通用策略**：每个 Phase 单独提交；数据库变更仅新增 Migration，不改已发布迁移；保留旧字段/旧 Token 的过渡兼容窗口；配置类改动可在部署层回退；不可预测的批量数据操作先备份再执行并提供补偿脚本。

**下一步**：等待项目负责人确认 Phase 0 四份文档与风险等级，尤其是 `00_BASELINE_AUDIT.md` §10 的五项人工决策，再开始 Phase B。

---

## Phase B 执行记录（已完成）

- 人工批准已收到；采用可逆默认：媒体缓存 `no-store`；归档清理与版本决策留到 C/E；API Session 项因复核确认无 Web 回退而取消。
- B1–B6 及 N-01~N-04 全部完成；新增迁移 `20261128_001_invitation_token_hash.php`。
- 回归：PHP lint / composer validate / PHPStan / secret-scan / JS check / migration status+repeat / doctor / `git diff --check` 均 rc=0；`bash bin/test.sh` 133 通过 / 0 失败（原 128 + 新增 5）。
- 阶段报告：`10_SECURITY_FIX_REPORT.md`、`11_AUTHORIZATION_TEST_REPORT.md`、`12_MEDIA_SECURITY_REPORT.md`；风险登记册已追加 Phase B 处置结果。
- 门禁：Phase B 全部通过，进入 Phase C；未 push main，未发 Release，未动生产。

---

## Phase C 执行记录（已完成）

- 人工决策：批准进入 Phase C；R-04 采用**队列异步清理**（先标记待清理、独立幂等任务清理，以便回滚）；AI 事实来源按任务书**完整新增一套字段**。
- 五路互不重叠写作用域：C1 DB/doctor、C2 AI 可靠性+事实来源、C3 归档异步清理、C4 请求追踪、C5 备份恢复校验；测试由主会话统一接入 `tests/run.php`。
- 新增迁移：`20261128_002_ai_fact_provenance.php`、`20261128_003_archive_cleanup_queue.php`、`20261128_004_audit_request_id.php`；累计 31 版本全部 applied，重复执行 rc=0。
- 关键修正：`database/schema.sql` 不再重复写由迁移管理的列；`bin/install.php` 安装后自动执行 `migrate-v2.php`，修复全新安装 + 027 ALTER 重复列失败；`Router` 的 API 401/403 改用 `Api::error()` 以携带 request_id。
- 回归：PHP lint rc=0；composer validate --strict rc=0；PHPStan `[OK] No errors` rc=0；secret-scan rc=0；node --check rc=0；`bash bin/test.sh` **138 通过 / 0 失败**（原 133 + Phase C 5 项）；doctor rc=0（0 阻断）；`git diff --check` rc=0。
- 恢复演练：`bin/backup.php` rc=0；`restore-check` rc=0（3 产物 sha256/bytes OK，tables=57 inserts=53）；隔离库 `jidian_restore` 导入 + Doctor rc=0 + 行数核对完成，演练库已删除。
- 阶段报告：`20_AI_WORKFLOW_REPORT.md`、`21_DATA_CONSISTENCY_REPORT.md`、`22_DATA_GOVERNANCE_REPORT.md`、`23_DATABASE_REPORT.md`、`24_AUDIT_REPORT.md`、`04_DATA_RETENTION_POLICY.md`、`05_BACKUP_RECOVERY_REPORT.md`；风险登记册 §5 已更新。
- 门禁：Phase C 已关闭风险已记录，剩余项进入 Phase D/E；未 push main，未发版，未动生产。

**下一步（已过期）**：等待人工批准进入 Phase D（P2 用户体验、小程序、性能、部署安全），并确认版本最终定为 v2.1.0 或 v2.0.2（可在 Phase E 决定）。

---

## Phase D 执行记录（已完成）

- 人工决策（verbatim 要点）：「1.补页面，2.接受」——补齐小程序缺失页面；接受 APP_DEBUG 默认 false。
- 四路互不重叠写作用域：D1 小程序页面（6 新页 × 四件套 + api.js/app.js）、D2 Web UX（引导 + 批量上传）、D3 配置/审计脱敏（APP_DEBUG + Audit sanitize + API actor）、D4 性能（批量可见性过滤 + LIMIT + 索引）；测试统一接入 `tests/run.php`。
- 新增迁移：`20261128_005_performance_indexes.php`；累计 32 版本全部 applied，重复执行 rc=0。
- 集成修正：4 个旧小程序页面 require 路径、families 详情/列表导航入口、Phase D 五项测试。
- 回归：PHP lint rc=0；node --check rc=0；composer validate --strict rc=0；PHPStan `[OK] No errors` rc=0；secret-scan rc=0；`bash bin/test.sh` **143 通过 / 0 失败**；doctor rc=0（0 阻断，3 警告）；`git diff --check` rc=0。`EXPLAIN` 日志 `/tmp/opencode/phase-d/explain.log` 确认索引生效。
- 阶段报告：`30_USER_EXPERIENCE_REPORT.md`、`31_MINIPROGRAM_REPORT.md`、`32_PERFORMANCE_REPORT.md`、`33_DEPLOYMENT_CHECKLIST.md`；风险登记册 §6 已更新。
- 门禁：Phase D 已关闭 R-25/R-26/R-27/R-28/R-29 及性能 P-01~P-06；剩余项进入 Phase E；未 push main，未发版，未动生产。

**下一步**：等待人工批准进入 Phase E（最终回归、备份恢复复验、发布准备），并确认版本最终定为 v2.1.0 或 v2.0.2。
