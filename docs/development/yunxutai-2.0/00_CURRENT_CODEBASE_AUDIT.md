# 云叙台 2.0 · 现有代码盘点（阶段 A）

> 标记含义：**CONFIRMED** = 本次已读代码/运行命令确认；**NEEDS_VERIFICATION** = 有线索但未实证；**PROPOSED** = 2.0 新增需求，不是现存缺陷。
> 盘点方法：以本地 `main` 工作树为对象（只读），未修改任何生产数据。GitHub 远端在盘点前已与本地同步；因网络间歇性不可达，未在本轮重新执行 `git pull`，远端最新状态标记为 NEEDS_VERIFICATION。

## 1. 代码提交 SHA

- CONFIRMED：`4e7365238c6478defb494f16265abc85e08dc69f`（main，工作树干净，短 SHA `4e73652`）。
- CONFIRMED：该提交之后已推送成功（`4e73652`），上一提交 `a7f6c24`、`dcdda62`。
- NEEDS_VERIFICATION：本轮未重新拉取 GitHub main；开发前必须再核对远端 SHA。

## 2. 运行环境版本

- CONFIRMED：本地 PHP 8.4.28 CLI（NTS）；`bin/doctor.php` 检查通过，扩展 pdo_mysql / mbstring / fileinfo / gd / openssl 齐备。
- CONFIRMED：本地数据库 MariaDB 11.8.9（`SELECT VERSION()`）。
- CONFIRMED：线上 Web 曾在响应头暴露 `PHP/8.5.12`（该头已在 `a7f6c24` 从应用层移除；线上运行时版本仍为 PHP 8.5.x，来源为 1Panel 运行时 `PHP8-5-12`）。
- NEEDS_VERIFICATION：线上数据库版本与字符集/排序规则；需在不改数据的前提下只读确认。
- CONFIRMED：Web 为 PHP 原生 SSR + 自建 Router，无 Composer 框架；数据库为 MySQL/MariaDB + PDO；缓存/限流默认 MySQL，可选 Redis；会话默认文件存储，可选 Redis。
- CONFIRMED：小程序通过 `/api/v1` 接入；生产域名 `https://yunxutai.cn`（www 301 到裸域）。

## 3. 现有业务模块

CONFIRMED（控制器与视图目录）：
- 公开展示：首页、纪念馆列表/详情、生平文章、公开纪念互动（献花/留言受审核）、关于/服务/隐私/条款。
- 账号：注册、登录、TOTP 2FA、账号设置、数据请求。
- 纪念馆管理（`app/Controllers/Manage/`）：资料、内容、成员、AI 工作室。
- 邀请与协作：`app/Controllers/InvitationController.php`、`invitations` 表、馆主成员管理。
- 身份核验：`memorial_verifications`、`verification_status`、管理员核验队列。
- 举报/认领/服务请求：`reports`、`claims`、`service_requests`、`data_requests`。
- 管理后台（`app/Controllers/Admin/`）：仪表盘、纪念馆、用户、核验、举报、认领、日志、AI 服务/订单/任务、站点设置。
- AI 工作室：`app/Services/AiStudioService.php`、`ai_services`、`ai_jobs`、`ai_orders`、`bin/ai-worker.php`。
- API：`app/Api/Controllers/*`，20 条 `/api/v1` 路由（详见第 10 节）。

## 4. 数据库表及字段

CONFIRMED（`database/schema.sql` 共 24 张表；字段摘要如下，完整定义以文件为准）：

| 表 | 关键字段（节选） |
|---|---|
| users | id, email, phone, password_hash, display_name, role, status, totp_secret, totp_enabled |
| api_tokens | id, user_id, label, created_at, last_used_at, expires_at, revoked_at |
| wx_identities | id, user_id, openid, unionid, provider |
| platform_settings / site_settings | name/setting_key, value/setting_value, updated_at |
| deceased | id, full_name, gender, birth_place, death_place, bio, cover_media_id |
| memorials | id, deceased_id, slug, title, summary, visibility, status, allow_search_index, allow_guestbook, moderate_messages, theme, verification_status, verified_at |
| memorial_verifications | id, memorial_id, submitted_by, method, statement, document_path, status, reviewer_id |
| memorial_members | id, memorial_id, user_id, role, status, invited_by |
| invitations | id, memorial_id, email, role, expires_at, accepted_at, revoked_at |
| life_events | id, memorial_id, year_label, title, description, location, sort_order |
| articles | id, memorial_id, title, author_name, status, review_note, published_at |
| media | id, memorial_id, uploader_id, kind, disk_path, original_name, mime, size_bytes, visibility, status |
| ai_services | id, slug, name, unit, price_cents, enabled |
| ai_jobs | id, memorial_id, user_id, service_slug, status, input_json, output_json, media_id, article_id, provider, cost_cents, error_message |
| ai_orders | id, memorial_id, user_id, service_slug, price_cents, status, task_id, payment_ref |
| tributes / messages | id, memorial_id, user_id, type/author_name, content/note, status, audited_at |
| reports / claims / service_requests / data_requests | id, 目标/主体, reason/type, status, handled_by, resolution |
| audit_logs | id, actor_user_id, action, target_type, target_id, meta, created_at |
| throttle | throttle_key, hits, reset_at |

CONFIRMED：唯一现存 SQL 迁移 `database/migrations/20261027_verification.sql`；**另有** `php bin/migrate.php` 的幂等 PHP 迁移机制（SQL 文件注释明确指向它）。现阶段 schema.sql 与各环境实际库是否完全一致：**NEEDS_VERIFICATION**。
CONFIRMED：无 `families`、`persons`、`archives`、`exhibitions`、`notifications` 等 2.0 表；全部为新增。

## 5. 用户认证机制

CONFIRMED：
- 会话 Cookie 名 `jingsi_session`（线上仍用此名，改名会踢下线）；`Auth::user()` 从会话取用户，`setApiUser()` 支持 API 上下文。
- 密码散列、登录限流（默认 5 次 / 900 秒）、TOTP 2FA（`app/Core/Totp.php`）、管理员重置他人 TOTP 均已实现并有测试。
- API 令牌：`api_tokens` 表，`ApiAuth::createToken/resolve/revoke`，TTL 30 天，Bearer Token；浏览器会话与 API 令牌是两套边界，`api-auth` 中间件单独校验。
- 微信身份：`wx_identities` 表 + `/api/v1/auth/wechat`。
- CSRF：Web POST 有令牌校验；API 使用 Bearer Token，不依赖会话 Cookie。
- 邮件驱动 log / smtp / none；`Notify` 统一发送与日志。
- PROPOSED：2.0 不得修改现有认证流程；新模块复用同一 `Auth` / `ApiAuth` / Policy。

## 6. 纪念馆权限体系

CONFIRMED（`app/Core/Policy.php`，110 行，静态内存缓存角色）：
- 平台角色：`admin`；纪念馆成员角色：`owner`、`editor` 等（存 `memorial_members.role`，仅 active 生效）。
- 能力：`canView` / `canEdit` / `canManage` / `canModerate` / `canPost` / `canViewMedia` / `isIndexable`。
- 未核验（`verification_status != verified`）纪念馆对非成员一律不可见；公开索引还需 `visibility=public` + `allow_search_index=1`。
- 私密媒体 `visibility=members` 仅成员可见；Web 与 API 控制器均调用 Policy。
- `clearCache()` 用于角色变更后清除请求内缓存。
- NEEDS_VERIFICATION：`memorial_members.role` 的完整枚举值集合（建议从迁移/种子与代码常量再次核对）。
- PROPOSED：扩展为家族级 RBAC + 资源级授权 + family_id 隔离（见 `05_PERMISSION_MODEL.md`）。

## 7. 文件存储机制

CONFIRMED（`app/Core/Storage.php`，242 行）：
- `uploadsPath/storeUpload/kindFromMime/storeVerificationDocument/storeGeneratedBytes/storeGeneratedFile/delete/absolute`。
- 本地磁盘 `storage/uploads/YYYY/MM/<random_hex>.<ext>`；随机文件名，权限 0640。
- MIME 白名单（jpeg/png/webp/gif/mp4/mp3/m4a/pdf）用 `finfo` 实测，大小限制照片 20MB / 视频 50MB（应用配置）；核验材料单独存 `storage/verifications`。
- 媒体访问经 `Media` 模型 + Policy；小程序用短时签名 URL（`/api/v1/media/{id}`）。
- 现有 upload_max_filesize=2M / post_max_size=8M 低于应用上限（`bin/doctor.php` 警告，CONFIRMED）。
- NEEDS_VERIFICATION：生产环境 PHP 上传限制与磁盘实际配置。
- PROPOSED：抽象统一文件服务（本地驱动优先），支持 SHA-256、版本、EXIF 清理、缩略图、下载审计和未来对象存储切换；业务层禁止拼接公开 URL。

## 8. AI 工作室实现

CONFIRMED：
- `app/Services/AiStudioService.php`（515 行）入口 `process(int $jobId): array`；`app/Services/Ai/AiClientFactory.php` + `OpenAiClient.php` 为 Provider 适配；`ai_services` 可后台启用/定价，`ai_orders` 为收费订单（收款确认制）。
- `ai_jobs` 保存输入/输出 JSON、provider、成本、错误、起止时间；任务产物写回 `media` / `articles`。
- 当前 Provider 为 `demo`（本地自检输出），线上可通过后台配置；实际启用 Provider 与密钥：**NEEDS_VERIFICATION**。
- PROPOSED：统一 AI 中枢扩展为 OCR / 转写 / 提取 / 传记 / 家族史工作流，保存模型、Provider、提示词版本、引用、审核状态；AI 结果不得自动成为正式事实。

## 9. AI 任务队列机制

CONFIRMED：
- `bin/ai-worker.php` 由 cron 调用（注释建议每 2 分钟、`--limit=3`）；读取 `AiJob::queued()`，`AiStudioService::process()` 处理，异常仅写日志并继续。
- `AiJob::markProcessing / markCompleted / markFailed / retry` 提供状态流转；无租约、无原子领取、无指数退避、无最大重试次数、无取消接口、无超时恢复。
- PROPOSED：幂等键、原子领取、租约、超时回收、失败分类、退避、取消、日志与清理（见 `03_ARCHITECTURE.md`、`04_DATA_MODEL.md`）。

## 10. API 路由结构

CONFIRMED：`app/routes-api.php` 共 20 条，全部经 `Api::route()` 统一 JSON 异常边界：
- 认证：`POST /api/v1/auth/token`、`/auth/wechat`（公开）；`POST /auth/logout`、`GET /me`（api-auth）。
- 媒体：`GET /api/v1/media/{id}`（签名 URL）。
- 读取：`GET /memorials`、`/memorials/{slug}`、`/memorials/{slug}/articles/{id}`。
- 互动：`POST /memorials/{slug}/tribute`、`GET/POST /memorials/{slug}/messages`。
- 举报/认领：`POST /reports`、`POST /memorials/{slug}/claim`。
- 管理：`GET/POST /manage/memorials`；成员：`GET .../members`、`POST .../invite`、`.../role`、`.../remove`、`.../invitations/{id}/revoke`。
- Web 路由 `app/routes.php` 约 120 行注册（`['admin']`、`['api-auth']` 等中间件参数），SSR 控制器分组见第 3 节。
- 小程序页面目录：`login / memorials / memorial / article / manage / account / report`（CONFIRMED）。
- PROPOSED：新增 families / persons / genealogy / archives / ai / events / exhibitions 路由，遵循现有命名与中间件约定。

## 11. 小程序功能情况

CONFIRMED：小程序已有登录、纪念馆列表/详情、文章、献花留言、馆主管理、账号、举报页面；通过 `/api/v1` 访问，媒体走签名 URL。
NEEDS_VERIFICATION：小程序当前线上版本、审核状态、订阅消息配置、基础库兼容范围。
PROPOSED：新增家族列表/主页、人物详情、谱系、档案、时间轴、AI 任务状态、通知中心；复杂编辑保留 Web。

## 12. 测试覆盖情况

CONFIRMED：`bash bin/test.sh` 使用独立 `jidian_test` 库（`--fresh --demo`）与 PHP 内置服务器（端口 8110），`tests/run.php` 共 **71 个测试**，本轮全部通过（详见 `01_BASELINE_TEST_REPORT.md`）。覆盖认证/2FA、权限与越权、上传与媒体签名、AI 任务与收费订单、通知、限流、API 各端点、SEO 输出等。
CONFIRMED：测试为单文件过程式测试运行器（`tests/run.php` + `tests/Support/`），无 PHPUnit；`tests/Integration/`、`tests/Security/` 目录尚不存在。
PROPOSED：按模块拆分集成测试与安全测试，沿用现有运行器风格。

## 13. 现有技术债

CONFIRMED（代码可证）：
1. 无 Composer/自动测试框架依赖，测试集中在单文件；模块增长后维护成本高。
2. `Policy` 请求内静态缓存角色，变更后必须显式 `clearCache()`，易漏。
3. AI 任务领取非原子（`queued()` + `markProcessing()`），worker 崩溃后 processing 任务无恢复机制；无重试上限/退避/取消。
4. 存储为本地磁盘直写，无统一文件服务接口；无 SHA-256、版本、EXIF 清理、下载审计。
5. 无数据库迁移框架；仅一个 SQL 迁移 + `bin/migrate.php`，结构变更缺乏版本表与自动回滚演练。
6. 无 CI（`.github/` 不存在），无静态分析/代码风格检查。
7. 无统一通知中心：`Notify` 只负责发信，业务事件未抽象为站内通知/偏好/日志。
8. 无审计抽象：`audit_logs` 表存在，但覆盖哪些操作需逐控制器确认（NEEDS_VERIFICATION 具体覆盖率）。
9. 本地 `APP_DEBUG=true` / `APP_ENV=local`；生产配置需单独确认（NEEDS_VERIFICATION）。
10. 上传大小受 PHP ini 限制（2M/8M），与应用 20M/50M 不一致。
11. 数据模型全部围绕纪念馆，缺少家族、人物（可无账户）、档案、关系、事件、展馆实体。
12. `deceased` 与 `users` 分离已实现，但“人物可关联纪念馆”尚无正式关联表（PROPOSED 新增）。

## 14. 新功能可复用的现有组件

CONFIRMED：
- `App\Core\Db`、`Validator`、`Request`、`Response`、`Router`、`Settings`、`Notify`、`Storage`、`Totp`；`app/helpers.php`（url/e/canonical 等）。
- `App\Core\Policy`（扩展而非重写）、`Auth` / `ApiAuth`、`Audit`（如现有日志写入工具，使用时核对实现）。
- `AiClientFactory` / `OpenAiClient`、`AiStudioService::process` 的调用骨架、`AiJob` 状态机。
- `bin/install.php`、`bin/migrate.php`、`bin/doctor.php`、`bin/backup.php`、VIEW 布局与 partials、`tests/run.php` 运行方式。
- 现有身份核验与举报流程（活人隐私门槛）应保留并复用于家族人物/关系审核。

## 15. 需要改造的现有组件

CONFIRMED / PROPOSED：
- `Policy`：从纪念馆级扩展到家族级能力与资源级校验，保持 Web/API 同源（PROPOSED 扩展）。
- `Storage`：包装为统一文件服务，保留本地驱动（PROPOSED）。
- `AiStudioService` / `AiJob` / worker：纳入统一工作流与可靠性机制，保持旧任务可处理（PROPOSED 扩展）。
- `bin/migrate.php` / `schema.sql`：引入版本化迁移，避免双源冲突（PROPOSED）。
- `tests/run.php`：分模块承载新增测试，不删除旧测试（PROPOSED）。
- layout / 导航：新增入口但保持庄重风格（PROPOSED）。

## 16. 需要新建的组件

PROPOSED（目录命名以现有规范为准，先核对后落地）：
- 服务层：`Family`、`Genealogy`、`Archive`、`FamilyTimeline`、`OralHistory`、`Ai`（工作流）、`Exhibition`、`Notification`。
- 模型/表：第 4 节缺失实体 + `04_DATA_MODEL.md` 中的全部新表。
- 控制器与视图：家族空间、谱系图、档案、AI 工坊、时间轴、展馆、通知中心。
- API：第 10 节规划路由；小程序对应页面。
- 基础设施：迁移版本表、审计服务、文件服务抽象、CI、健康检查与 Worker 监控。

## 盘点结论

CONFIRMED：现有系统是单体 SSR + PDO + 自建路由，业务围绕纪念馆；认证、权限、存储、AI 任务、API 边界均已存在，可作为 2.0 的复用底座。
NEEDS_VERIFICATION：生产环境配置、数据库版本、AI Provider、审计覆盖率、小程序线上版本、GitHub 远端最新 SHA。
PROPOSED：2.0 不是并列新系统，而是在现有能力上扩展家族/档案/AI/展馆与统一权限；所有新表独立迁移，所有新功能复用 Policy 与现有认证。

> 未确认事项不得在后续开发中当作已知事实；进入阶段 B 前必须逐项核实本文件的 NEEDS_VERIFICATION 条目。
