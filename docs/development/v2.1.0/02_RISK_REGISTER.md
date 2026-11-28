# 云叙台 v2.1.0 — 风险登记册（Phase 0）

基线：`49fc14e94adeadcf4476873f8e19413ac16a30f9`
标注：**CONFIRMED**＝有代码证据；**NEEDS_VERIFICATION**＝需测试/环境确认；**PROPOSED**＝修复方向待批准。
严重度：P0＝身份/权限/隐私/数据泄露级；P1＝数据一致性、可靠性、门禁可信度级；P2＝体验/可维护性/纵深防御级。
修复阶段：B＝P0 安全；C＝P1 数据与任务可靠性；D＝P2 体验与性能；E＝最终回归与发布准备。

---

## 1. 已确认问题（CONFIRMED）

### 1.1 P0

| ID | 领域 | 发现 | 代码证据 | 影响 | 修复阶段 | 回滚 |
|---|---|---|---|---|---|---|
| R-01 | 媒体安全 | `APP_KEY` 缺失时静默回退字面量 `dev-only-key-change-before-production`，该值是签名 URL 唯一 HMAC 密钥；签名端点无鉴权且只检查 `status='active'`，不复核 `Policy::canViewMedia()` | `config/config.php:4-7`；`app/Core/MediaSign.php:36-39`；`app/Api/Controllers/MediaController.php:22-35`；`app/routes-api.php:31` | 知道回退密钥者可伪造任意媒体 ID 的 900s 访问 URL，绕过私有可见性 | B | 启动时强校验只阻断部署，不改存储数据；恢复环境变量即可 |
| R-02 | 权限/邀请 | 纪念馆邀请链接是纯 bearer 凭证：明文 token 入库、任何登录用户持 token 即可接受并获角色；家族邀请则校验 email 相等 | `app/Models/Invitation.php:13-20,27-35`；`app/Controllers/InvitationController.php:81-88`；对比 `app/Services/FamilyService.php:187-192`；可空 email 见 `app/Api/Controllers/MemberController.php:69-75,98-104` | 跨用户/跨家族越权加入，破坏成员隔离 | B | 新增 hash 列需迁移；保留旧 token 验证窗口并审计 |
| R-03 | AI 授权 | 外部 AI 处理的同意只在创建时检查；Worker 明确不复核用户级授权，撤销同意不取消 `processing` 任务且漏掉 `failed`，而 `recoverExpired()` 会把 `failed` 重新入队 | `app/Services/AiWorkflowService.php:38-39,548-578,585-586,696-721,742-747,209-221`；`app/Controllers/Manage/AiController.php:58-61` | 用户撤销同意后仍可能向外部 Provider 发送私有资料 | B/C | 只改状态流转与校验，不改已生成事实；保留审计 |
| R-04 | 数据治理 | 已批准的归档删除只改 `status/deleted_at`，`archive_files` 行与 `storage/uploads/archives/...` 物理文件均未清理 | `app/Services/ArchiveService.php:467-493`；grep 无 cleanup 调用；`00_BASELINE_AUDIT.md` C-03 | 隐私数据可恢复，违背删除/导出治理承诺 | C | 采用“待清理队列 + 幂等清理任务”可回滚；批准即删则不可逆，需人工决策 |

### 1.2 P1

| ID | 领域 | 发现 | 代码证据 | 影响 | 修复阶段 | 回滚 |
|---|---|---|---|---|---|---|
| R-05 | DB 基线 | 迁移 027 新增 `lock_version/heartbeat_at/last_error_at`，`schema.sql` 未同步；`install.php` 只执行 schema.sql，`bin/test.sh` 靠随后跑迁移掩盖 | `database/migrations/20261127_027_ai_workflow_locking.php:8-12`；`database/schema.sql:728-757`；`bin/install.php:65-76`；`bin/test.sh:22-26` | 全新安装后 AI 工作流 claim/process 报 SQL 错误 | C | 新增独立迁移/更新 schema.sql；已发布迁移语义不变 |
| R-06 | AI 并发 | Web `runWorkflow()` 调 `process($id,'web-...')` 不传 `lockVersion`，写入退化为无条件 `id=?`，绕过 CLI 的 fencing | `app/Controllers/AiWorkshopController.php:92`；`app/Services/AiWorkflowService.php:273-276,311-315`（对比 `:165-171,252`） | 过期 Worker 或 Web 运行可覆盖新执行者结果；取消任务可被写回 | C | 只改条件更新与传参；失败时任务保持 `failed` 可重试 |
| R-07 | AI 并发 | `heartbeat()` 续租 UPDATE 失败时回退到 `owns()`，而 `owns()` 不检查 `lease_until` 过期 | `app/Services/AiWorkflowService.php:150-171` | 租约已过期的 Worker 仍被判定持有任务，可继续写入 | C | 收紧为严格条件；旧 Worker 会在下次心跳失去租约 |
| R-08 | AI 并发 | 创建幂等靠预 SELECT + 唯一索引，无重复键捕获；无 key 时随机生成，重复请求不幂等 | `app/Services/AiWorkflowService.php:41-71`；`database/migrations/20261127_017:32` | 并发重复创建产生 500/PDOException 或重复任务 | C | 捕获重复键返回既有记录；不改业务语义 |
| R-09 | AI 订单 | 管理员确认订单无事务、无条件状态 UPDATE、订单表无唯一约束，并行确认可创建多个 job | `app/Controllers/Admin/AiOrderController.php:46-94`；`database/schema.sql:268-292` | 重复扣费/重复 AI 任务 | C | 条件状态 UPDATE + 事务；重复确认返回冲突 |
| R-10 | AI 状态 | 步骤状态从未被设为 `processing`，成功时批量标 `completed`、失败批量标 `failed`，不反映真实执行进度 | `app/Services/AiWorkflowService.php:257-262,296-300`；`database/migrations/20261127_018:13` | 步骤级可观测性和重试依据失真 | C | 改为逐步推进；保留旧步骤数据兼容 |
| R-11 | AI 重试 | 重试分类忽略 `ProcessingException` 的 `isRetryable()`，`auth_error`/4xx 也按 `provider_error` 重试；`process()` 无内部超时/截止时间 | `app/Services/AiWorkflowService.php:289-295,302`；`app/Services/Ai/ProcessingClient.php:214-221,237-240` | 永久错误耗尽重试、任务超时不可控 | C | 只改分类与超时配置；失败任务可人工重试 |
| R-12 | 会话安全 | 修改密码只更新 hash/session/CSRF，不吊销该用户 API Token（Token 有效期 30 天） | `app/Controllers/AccountController.php:81-117`；`app/Core/ApiAuth.php:14,25,77-90` | 密码泄露/改密后旧 Token 仍可用 | B | 改密时批量 revoke；用户需重新登录小程序 |
| R-13 | 登录安全 | 登录限流按 `(email,IP)` 5/900，IP 维度 30/3600 只在账号 key 已耗尽后才递增，单 IP 对每个不同账号 5 次猜测不会触发 IP 封禁 | `app/Controllers/AuthController.php:113,121-122` | 密码喷洒（password spraying）风险 | B | 每次尝试都计数 IP；误封可用白名单/管理员解除 |
| R-14 | 恢复验收 | `bin/restore-check.php` 仅 gunzip 后统计 CREATE/INSERT，退出码只取决于 `tables>0`；不导入 DB、不校验行数/文件、不比对 manifest checksum；CI/测试均无真实恢复演练 | `bin/restore-check.php:9-15`；`deploy/../docs` 声称“结构与数据完整”；`bin/test.sh`、`.github/workflows/ci.yml` 无 restore 步骤 | 备份可用性被错误宣称，灾难恢复不可信 | E | 新增隔离环境演练脚本，不触碰生产 |
| R-15 | Doctor | Doctor 必需表只列 9 张旧表，不检查 v2 表与 `schema_migrations` 状态；警告不阻断 | `bin/doctor.php:119-130` | 缺表/迁移失败仍可能通过 CI | C/E | 只增检查项；旧环境需先补迁移 |
| R-16 | 迁移测试 | `tests/run.php:2164-2188` 只断言 `001-006` 版本与 checksum 非空，覆盖不到 `007-027` | `tests/run.php:2166-2171` | 迁移质量门禁形同虚设 | C | 扩展断言至全部版本；不改历史迁移 |
| R-17 | 备份完整性 | `bin/backup.php` 的 `manifest.json` 无 checksum、无加密；文档自认无内置加密且单点存储 | `bin/backup.php:97-102`；`docs/deployment.md:138-160`；`docs/security.md:104-105` | 备份可被篡改/泄露，恢复时无法验证 | E | 加 sha256 与可选加密；保持旧格式兼容读取 |
| R-24 | 可观测性 | 全项目无 `request_id`/`X-Request-Id`：审计表、API 错误信封、全局异常处理、响应头均未携带 | grep 全仓 0 命中；`app/Core/Audit.php:18-25`；`app/Api/Api.php:34-43`；`app/error-handler.php:42,73-77`；`database/schema.sql:403-416` | 日志/审计/用户反馈无法关联，故障定位困难 | D/E | 新增字段与头字段，旧记录为空兼容 |
| R-25 | 配置安全 | `config/config.php:16` 默认 `APP_DEBUG=true`，当前 `.env` 为 true/local；debug 路径在 `error-handler.php:43-49` 与 `errors/debug.php:6-7` 暴露文件/行号/完整堆栈（实际生产暴露取决于部署 env） | `config/config.php:16`；`app/error-handler.php:38-49,76`；`app/Views/errors/debug.php:6-7` | 若生产误配将泄漏路径/SQL/堆栈 | B/D | 改默认值并加生产 fail-fast；无需数据变更 |
| R-26 | 审计隐私 | API Token 签发路径未写审计，`Audit::log()` 仅从 Session 取 actor；meta 中记录完整邮箱、姓名、异常原文、设置原始负载 | `app/Core/Audit.php:17`（133 处调用）；无调用于 `app/Api/Controllers/AuthController.php` / `app/Api/WechatLogin.php`；`app/Controllers/Admin/UserController.php:60-62,84-85`；`app/Services/GenealogyService.php:344-346`；`app/Services/AiWorkflowService.php:316-317`；`app/Services/MemorialService.php:194`；`app/Controllers/Admin/LogController.php:29-43` | 审计无法归因 API 操作，且可能存储/展示敏感信息 | B/D | 增 `actorId` 参数与脱敏函数，旧日志不变 |

### 1.3 P2

| ID | 领域 | 发现 | 代码证据 | 影响 | 修复阶段 | 回滚 |
|---|---|---|---|---|---|---|
| R-18 | 上传补偿 | `ArchiveService::addFile()` 用 `catch (Throwable)` 但文件命名空间为 `App\Services`，未导入全局类，补偿逻辑为死代码 | `app/Services/ArchiveService.php:4-10,210` | DB 失败后文件孤儿 | C | 改 `\Throwable`；无数据迁移 |
| R-19 | 媒体缓存 | 私有媒体响应 `Cache-Control: private, max-age=300`，授权变更后浏览器缓存仍可访问 | `app/Controllers/MediaController.php:42`；`app/Api/Controllers/MediaController.php:48` | 权限撤销延迟生效 | B | 改 `no-store/must-revalidate`；需评估带宽 |
| R-20 | 文件完整性 | JPEG 重编码后再 `hash_file()`，重编码失败被静默忽略；记录的是转换后副本哈希，不是原始字节 | `app/Core/Storage.php:121-138` | “SHA-256 完整性”表述失真，EXIF 可能残留 | C | 先哈希后转换或改文案；不改文件字节 |
| R-21 | 迁移卫生 | `20261027_verification.sql` 不在版本化 runner（glob `*.php`）且自声明非幂等 | `bin/migrate-v2.php:43`；该文件 `:2-3` | 旧环境升级路径不确定 | C | 纳入独立迁移或明确仅一次性脚本；需人工决策 |
| R-22 | 测试脚本 | `bin/test.sh:38-43` 服务探测全部失败仍继续；`TEST_DB` 可指向任意数据库并被 `--fresh` 删除 | `bin/test.sh:9,22,38-43` | 误报成功；误删非测试库风险 | E | 限定 `jidian_test` 前缀并失败即退出 |
| R-23 | AI 通知 | `ai_workflow_completed` 通知类型已声明但 AI 工作流代码未调用 `emit()`；测试手动补发 | `app/Services/NotificationService.php:16`；`tests/run.php:2760-2766` | 用户收不到完成通知 | C | 增发通知并去重；无数据结构变更 |
| R-27 | 用户体验 | 上传控件为单文件，无批量选择与进度/失败列表；仅 `FormData` 出现在 admin-ai-settings.js | `app/Views/manage/media.php:67`、`app/Views/archive/show.php:74`、`app/Views/manage/verification.php:81`；`public/assets/js/admin-ai-settings.js:23` | 大批量资料录入体验差，失败不可见 | D | 前端队列增强，复用现有端点；可回退 |
| R-28 | 用户体验 | 无多步新手引导：仅首页静态文案与一次性空状态，无步骤清单/进度保存 | `app/Views/home.php:14-20,49-52`；`app/Views/account/dashboard.php:39-45`；全仓 grep 无 onboarding/引导步骤 | 新用户不知道下一步，创建闭环弱 | D | 增引导组件；纯视图改动 |
| R-29 | 小程序 | 小程序仅 12 页，缺少人物/谱系/时间线/展览/AI 工作流/邀请接受页，而 API 已暴露；`ensureSession()` 定义后无任何调用，401 一律强制 reLaunch 登录 | `miniprogram/app.json:2-15`；`miniprogram/utils/api.js:197-217`；`miniprogram/utils/auth.js:96-126,145`；`app.js:12-25` | 小程序能力不完整、Token 过期体验差、状态丢失 | D | 接线 `ensureSession()` 并补齐页面或声明 Web-only；回退为原 401 处理 |
| R-30 | 部署安全 | Nginx 边缘仅 TLS/HSTS/nosniff/Referrer 三条，无 CSP；应用层的 CSP 仅当响应经 PHP 时生效 | `deploy/nginx.conf:20-25` vs `app/bootstrap.php:92-96` | 静态资源/直接文件响应缺 CSP | D | 边缘补 CSP 并先盘点资源，避免阻断业务 |

---

## 2. 待验证风险（NEEDS_VERIFICATION，不得当作已确认）

| ID | 领域 | 风险描述 | 验证方法 | 若确认的修复阶段 |
|---|---|---|---|---|
| N-01 | 身份缓存 | `Auth::$user/$resolved` 与 `Policy::$roles` 静态缓存可能在长生命周期中残留；纪念馆成员角色/撤销路径未调 `Policy::clearCache()` | 长驻 server 内先解析身份/角色，再在 DB 中禁用用户或降权，断言二次请求被拒 | B |
| N-02 | Cookie 安全 | Session `secure` 标志信任客户端 `X-Forwarded-Proto`；代理未设置时 Cookie 缺 Secure | 经代理发 TLS 请求且不传该头，检查 `Set-Cookie` | B |
| N-03 | 限流 fail-open | `RateLimiter::blocked()` 无 `hit()` 的 try/catch fail-closed | 停 Redis/MySQL 后 POST `/login`，期望 429/503 而非 500 | B |
| N-04 | 2FA 绑定 | 2FA pending 状态不绑定 UA/IP，复制 cookie 可从别处完成验证 | 不同 UA/IP 完成 `/2fa`，期望拒绝 | B |
| N-05 | DB 兼容 | `SKIP LOCKED` 依赖实际 MySQL/MariaDB 版本；27 个迁移在双引擎行为未实跑 | `SELECT VERSION()` + 真实 2 Worker 领取竞争 + `bash bin/test.sh` 双引擎 | C/E |
| N-06 | 上传绕过 | `finfo` + 白名单无 `getimagesize()`；polyglot、文件名注入、storage_key 编码绕过未测 | GIF89a+HTML 上传、`curl -i` 检查下载头、构造 `%2e%2e/`/NUL storage_key | B |
| N-07 | 签名撤销 | 已签发 900s 签名 URL 在可见性降级/成员撤销后仍可用；浏览器缓存叠加 | 签发后改 `members`，立即与缓存后重放；期望 403/404 | B |
| N-08 | AI 输入上限 | 自由文本 `text` 在创建与 prompt 中无长度上限 | 大输入测量 provider 成本/延迟与超时 | C |
| N-09 | AI 可见性 | 公开家族工作流草稿可能通过 `family.view` 暴露（`Views/ai/show.php:38-47`、`getVisible():753-761`），测试只覆盖私有家族 | 用公开家族建草稿，匿名/其他用户访问断言 | B/C |
| N-10 | 生产配置 | AI 关闭时 `AiClientFactory::makeFor('text')` 抛错；生产 `APP_KEY`/AI 设置未知 | 核对生产配置（需授权读取，不修改） | B |
| N-11 | 恢复演练 | 文档要求的季度真实恢复演练未见证据 | 隔离环境导入备份、比对行数与文件、启动应用验证媒体鉴权 | E |
| N-12 | 部署调试 | 生产是否误配 `APP_DEBUG=true`；staging 强制 500/404 检查是否泄漏 debug/trace | 部署环境读取配置（需授权）并触发异常 | B/D |
| N-13 | 日志泄漏 | 全局 handler 与 `Api.php` 记录原始异常文本，可能在 `storage/logs/` 或 `/admin/logs` 显示 SQL/路径 | staging 制造 DB 失败并检索日志/审计页 | D |
| N-14 | 小程序 401/403 | 12 个页面在 Token 过期与非所有者访问时是否丢失状态；全局 reLaunch 影响 | 逐页模拟过期 Token/越权 | D |
| N-15 | 签名媒体刷新 | 签名 2 次刷新上限后用户可见状态（`miniprogram/pages/memorial/detail.js:488-509`） | 过期 sig 后观察页面 | D |
| N-16 | 谱系图移动端 | 真实设备触控测试，当前无双指缩放（`genealogy.js:114-126,189-192`） | 真机手势测试 | D |
| N-17 | 上传吞吐 | 顺序上传多个 20/50MB 文件的超时与内存表现（`deploy/nginx.conf:27-29,55`） | 合成文件批量上传 | D |

---

## 3. 风险处置原则

1. 所有 P0 必须在 Phase B 修复并通过新增测试，否则不得进入发布准备。
2. 所有新增数据库结构变更必须走独立 Migration，不改已发布迁移语义，并同时回写 `schema.sql`；需支持 MySQL 8.4 与 MariaDB 11。
3. 不得通过删除测试、降低 PHPStan level、扩大 baseline/allowlist、缩矩阵、吞异常或虚假成功来消除风险。
4. 每项修复需在 `03_IMPLEMENTATION_PLAN.md` 中有对应测试、验收标准与回滚方案。
5. R-04（归档物理删除时机）、N-10（生产配置）等涉及业务/法律判断的项，须人工确认后再实施。

---

## 4. Phase B 处置结果（2026-11-28）

| ID | 处置 | 结果 | 证据 |
|---|---|---|---|
| R-01 | 已修复：APP_KEY fail-closed（含 APP_ENV 未设置）；签名绑定 visibility，TTL 300s；签名端点有身份时复核 Policy | PASS | `10_SECURITY_FIX_REPORT.md` B-1/B-2/B-10；`B-R01`、`B-B1/B5` |
| R-02 | 已修复：token_hash 迁移 + 邮箱绑定 + 禁止空邮箱邀请 | PASS | `B-R02` |
| R-03 | 已修复：执行前复核同意、撤销覆盖 failed、恢复不复活 | PASS | `B-R03` |
| R-12 | 已修复：改密批量撤销 Token | PASS | `B-R12` |
| R-13 | 已修复：每次尝试计 IP，成功清零 | PASS | 全量 133/0（无 429 回归） |
| R-19 | 已修复：no-store, must-revalidate | PASS | `B-B1/B5` |
| N-01 | 已修复：Auth::clear() + 角色/状态变更调用 | PASS（代码+全量测试） | Phase B 报告 |
| N-02 | 已修复：可信代理开关 | PASS（代码） | 配置与 bootstrap |
| N-03 | 已修复：blocked() fail-closed | PASS（代码），未做故障注入 | Phase C/E 环境验证 |
| N-04 | 已修复：2FA UA/IP 绑定 | PASS（代码），未做跨 UA 自动化 | Phase E 环境验证 |
| N-07 | 部分修复：可见性绑定 + TTL 300s + no-store；成员级撤销的 per-user 绑定未实现 | 残余风险 | `12_MEDIA_SECURITY_REPORT.md` §4 |
| R-25/R-26 | 未修复，按计划进入 Phase D | OPEN | — |

**Phase B 结论**：P0 全部关闭；P1 的 R-12/R-13 提前关闭。无测试删除、无门禁降级。

## 5. Phase C 处置结果（2026-11-28）

| ID | 处置 | 结果 | 证据 |
|---|---|---|---|
| R-04 | 已修复：先标记 deleted + 入队，独立幂等 worker 异步清理；共享文件保护；可回滚 | PASS | `22_DATA_GOVERNANCE_REPORT.md`；`C4` 归档清理测试 |
| R-05 | 已修复：schema.sql 不重复写迁移管理列；`install.php` 安装后自动跑 migrate-v2；doctor 检查迁移状态 | PASS | `23_DATABASE_REPORT.md`；全新安装 + 31 版本 applied |
| R-06/R-07 | 已修复：owns() 加租约判断；process 全路径 fencing；取消后旧 token 不可写回 | PASS | `C2` fencing 测试；全量 138/0 |
| R-08 | 已修复：create() 捕获 23000 回查既有 id | PASS | 全量 138/0 |
| R-09 | 已修复：订单确认事务 + FOR UPDATE + 条件状态校验 | PASS（代码+串行测试） | 并发 10 路专项列 E |
| R-11 | 部分修复：ProcessingException 分类与不可重试语义已生效；内部总超时未实现 | 残余风险 | `20_AI_WORKFLOW_REPORT.md` §4 |
| R-10 | 未修复：步骤仍批量置位，不反映真实执行进度 | OPEN（列 E） | `20_AI_WORKFLOW_REPORT.md` §4 |
| R-14 | 已修复：restore-check 校验全部产物 sha256/bytes + SQL 行数；完成隔离恢复演练 | PASS | `05_BACKUP_RECOVERY_REPORT.md` |
| R-15 | 已修复：doctor 必需表 9→21，新增迁移状态检查 | PASS | doctor rc=0，0 阻断 |
| R-16 | 部分修复：测试扩展到全部 20261128 版本与迁移状态 | 残余风险 | `23_DATABASE_REPORT.md` §5 |
| R-17 | 已修复：manifest 加 sha256/bytes；加密/异地仍 OPEN | PASS（完整性）/OPEN（加密） | `05_BACKUP_RECOVERY_REPORT.md` |
| R-18/R-20/R-21 | 未修复，列 D/E | OPEN | 本阶段最小改动原则未触碰 |
| R-23 | 未修复：AI 完成通知未 emit | OPEN（列 E） | `20_AI_WORKFLOW_REPORT.md` §4 |
| R-24 | 已修复：X-Request-Id / 顶层 request_id / 审计字段 / API 中间件信封 | PASS | `24_AUDIT_REPORT.md`；`C4` 测试 |
| N-08/N-09 | 部分修复：事实来源字段已补；自由文本上限与公开草稿可见性待定 | 残余风险 | `20_AI_WORKFLOW_REPORT.md` §4 |

**Phase C 结论**：R-04（用户选定队列异步清理）、R-05~R-09、R-14、R-15、R-17（完整性）、R-24 已关闭；R-10、R-11（超时）、R-16（细粒度）、R-18、R-20、R-21、R-23、R-25/R-26 等进入 D/E。全量 138/0；未 push main、未发版、未动生产。

## 6. Phase D 处置结果（2026-11-28）

| ID | 处置 | 结果 | 证据 |
|---|---|---|---|
| R-25/R-26 | 已修复：APP_DEBUG 默认 false + production fail-closed；Audit 元信息脱敏；API 令牌/微信登录审计带 actor；服务层敏感 meta 改哈希/字段名 | PASS | `D：审计脱敏…`、`D：production+debug fail-closed`、`D：API 令牌签发审计 actor` |
| R-27 | 已修复：媒体/档案批量上传队列 + 逐文件进度/失败重试 | PASS（代码） | `30_USER_EXPERIENCE_REPORT.md`；`batch-upload.js` |
| R-28 | 已修复：Dashboard 五步非阻断引导 + localStorage 跳过/恢复 | PASS | `30_USER_EXPERIENCE_REPORT.md`；`app.js` |
| R-29 | 已修复（用户决策「补页面」）：小程序新增 6 页（人物/谱系/时间线/展览/AI/邀请接受），入口与 require 路径同步修正；401 静默续登接入 | PASS（代码+node --check） | `31_MINIPROGRAM_REPORT.md`；`app.json` |
| R-30 | 部分修复：nginx CSP 以注释示例加入部署模板，应用层 CSP 未改；生产边缘配置需运维启用 | 残余风险 | `33_DEPLOYMENT_CHECKLIST.md` |
| P-01~P-06 | 已修复：人物/关系/档案列表 N+1 与无界查询；批量可见性过滤 + LIMIT；索引迁移 005 | PASS（`EXPLAIN`） | `32_PERFORMANCE_REPORT.md`；`D：性能索引迁移已应用` |
| N-07 | 仍为残余风险：签名绑定可见性 + TTL 300s + no-store，但无 per-user 绑定 | OPEN | `12_MEDIA_SECURITY_REPORT.md` §4 |
| R-10/R-11/R-16/R-18/R-20/R-21/R-23 | 未修复，保留至 Phase E | OPEN | `20_AI_WORKFLOW_REPORT.md`、`23_DATABASE_REPORT.md` |

**Phase D 结论**：用户两项决策已执行（补页面、接受 APP_DEBUG 默认关闭）。全量 143/0；32 个迁移版本全 applied；PHP lint / PHPStan / secret-scan / JS check / doctor / `git diff --check` 均 rc=0。未 push main、未发版、未动生产。真机走查、大规模性能数据集、服务端精确引导进度、边缘 CSP 启用列为 E/运维项。

## 7. Phase E 处置结果（2026-11-28）

| ID | 处置 | 结果 | 证据 |
|---|---|---|---|
| R-18 | 已修复：`catch (\Throwable)`；清理 PHPStan 过期 baseline 2 条 | PASS | `phpstan [OK] No errors` rc=0 |
| R-22 | 已修复：`TEST_DB` 前缀限制 + 服务器探测失败即退出 | PASS | `bash bin/test.sh` 145/0 |
| R-23 | 已修复：AI 完成发站内通知并按 link 去重，失败只记录日志 | PASS | `E：AI 工作流完成写入站内通知且只写一次` |
| R-16 | 部分修复：迁移 checksum 断言扩展至全部 32 个文件 | PARTIAL | `2.0 迁移版本记录完整且可重复执行` |
| R-20 | 部分修复：UI 文案改“入库文件 SHA-256”；原始字节哈希仍缺失 | PARTIAL | `app/Views/archive/show.php` |
| R-11 / R-10 / R-21 / R-30 | 保留残余/OPEN，进入正式发布门槛 | OPEN / PARTIAL | `93_UNRESOLVED_RISKS.md` |
| N-07 | 残余：可见性绑定 + TTL + no-store，缺 per-user 撤销 | OPEN | `12_MEDIA_SECURITY_REPORT.md` |

**Phase E 结论（30 个确认问题）**：完全关闭 **24** 项，部分修复 **4** 项（R-11/R-16/R-20/R-30），未修复 **2** 项（R-10/R-21）。全量 **145/0**；32 迁移全 applied；备份恢复演练通过；版本定为 v2.1.0；远程 CI 以 PR checks 为准。
