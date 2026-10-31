# 云叙台 · 实现约定

## 技术栈与运行

- PHP 8.4+（目标部署 PHP 8.5）、MySQL 8.4 LTS / MariaDB 10.11+、无框架、服务端渲染。
- 入口：`public/index.php`；路由：`app/routes.php`；核心：`app/Core`；模型：`app/Models`；服务：`app/Services`；视图：`app/Views`。
- 安装：`php bin/install.php --demo`；本地：`php -S 127.0.0.1:8099 -t public`。
- 本机演示账号见安装输出。演示纪念馆：`/m/zhang-mingyuan`。

## 请求与响应

- 控制器方法签名：`method(Request $request, ...$routeParams): Response`。
- 读输入：`$request->input('field')`（自动 trim）、`$request->raw('password')`、`$request->int()`、`$request->bool()`、`$request->file('file')`。
- 所有 POST 必须首先 `Csrf::verify($request);`。
- 返回：`View::render('manage/xxx', $data, 'layout', $status)`、`Response::redirect('/path')`。
- 视图内转义一律 `e($value)`；局部视图 `App\Core\View::partial('partials/name', [...])`。
- 布局变量：`$title`（不含站名）、`$noindex`（bool）、`$metaDescription`、`$canonical`、`$bodyClass`、`$content`。
- Flash：`Flash::success/error/info()`；布局自动渲染并清空。
- 分页：`App\Core\Paginator`，视图可用 `$paginator->page/pages/total/perPage/offset/hasPrev()/hasNext()/prevPage()/nextPage()`。

## 认证与权限（服务端强制）

- `Auth::user()`、`Auth::check()`、`Auth::isAdmin()`；路由中间件已配置 `auth` / `admin`。
- `Policy::role($memorial, $user)` 返回 `owner/editor/viewer/admin/null`。
- `Policy::canView / canEdit / canManage / canModerate / canPost / canViewMedia / isIndexable`。
- 控制器内必须再次校验：查看纪念馆、编辑内容、成员管理、审核操作。不得只依赖隐藏按钮。
- 媒体文件在 `storage/uploads`（Web 根之外），只能通过 `/media/{id}` 鉴权输出。

## 模块与数据

### 身份核验与公开门槛

1. 新建纪念馆固定 `private` + `draft`，仅馆主/协作者可见。
2. 馆主在 `/manage/memorials/{id}/verification` 提交材料；文件写入 `storage/verifications`（Web 根之外），元数据写 `memorial_verifications`，馆状态置 `pending`。
3. 管理员在 `/admin/verifications` 审核；通过后馆 `verification_status=verified`，才允许 `public|unlisted`、发布与 sitemap；驳回记录理由。
4. `Policy::canView` 与公开列表/`sitemap` 查询均要求 `verified`，成员/管理员例外。
5. 公开页展示核验标记；材料下载仅管理员，写审计。

### AI 工作室

1. 馆主在 `/manage/memorials/{id}/ai` 选择四项服务：照片修复、纪念短片、生平文章、家族档案。
2. `AiJob` 记录服务、输入、状态与输出；同步模式在请求内执行，异步由 `php bin/ai-worker.php` 消费。
3. 照片修复输出新副本，不覆盖原图；文章写 `draft`；短片由 ffmpeg 合成 MP4；档案为自包含 HTML。
4. 所有输出默认 `members` 可见，需馆主显式切换；价格存 `ai_services.price_cents`，后台可改，内测期不扣费。
5. `AI_PROVIDER=demo` 使用本地 GD/模板/ffmpeg；`openai` 才发送照片与文本到第三方，禁止发送核验材料。

表结构以 `database/schema.sql` 为准。关键字段：

- `users`: email, password_hash, display_name, role(user/admin), status(active/disabled)。
- `deceased`: full_name, gender, birth_date, death_date, birth_place, death_place, bio, cover_media_id。
- `memorials`: deceased_id, slug, title, summary, visibility(public/unlisted/private), status(draft/active/suspended), allow_search_index, allow_guestbook, moderate_messages, verification_status(none/pending/verified/rejected), verification_method, verified_at/by, created_by, published_at, suspended_reason。
- `memorial_verifications`: memorial_id, submitted_by, method, statement, document_path/name/mime/size, status(pending/approved/rejected), reviewer_id, review_note, reviewed_at。
- `ai_services`: slug, name, description, unit, price_cents, price_note, enabled, sort_order。
- `ai_jobs`: memorial_id, user_id, service_slug, status(queued/processing/completed/failed), input_json, output_json, media_id, article_id, provider, cost_cents, error_message, started_at/finished_at。
- `memorial_members`: memorial_id, user_id, role(owner/editor/viewer), status(active/revoked)。
- `invitations`: email?, role(editor/viewer), token, expires_at, accepted_at, revoked_at。
- `life_events`: event_date?, year_label?, title, description, location, sort_order。
- `articles`: title, content, author_name, status(draft/pending/published/rejected/hidden), review_note, published_at。
- `media`: kind(photo/video/audio/document), disk_path, original_name, mime, size_bytes, caption, visibility(visitors/members), status(active/hidden)。
- `tributes`: type(flower/candle/incense/bow), note, is_anonymous, status(visible/pending/hidden), created_at。
- `messages`: author_name, content, status(pending/visible/hidden)。
- `reports`: target_type(memorial/message/tribute/media/article), target_id, memorial_id, reason, priority(normal/high/urgent), detail, status(open/reviewing/resolved/dismissed), resolution。
- `claims`: relationship, statement, evidence_note, status(open/approved/rejected), resolution。
- `data_requests`: type(export/delete/revoke), status(open/processing/done/rejected)。
- `service_requests`: service_type, status(new/contacted/closed)。
- `audit_logs`: action, target_type, target_id, meta(JSON)。

模型已提供常用查询/写入方法，优先复用，不重复造 SQL；确需新查询可加模型静态方法，但不要改动既有方法签名。

## 模块清单

- 公共：`HomeController`（首页/纪念馆列表/sitemap）、`MemorialController`（纪念馆与栏目/申领）、`TributeController`（祭奠/留言）、`MediaController`（鉴权输出）、`ReportController`（举报）、`PageController`（关于/隐私/条款/服务）、`AuthController`。
- 账号：`DashboardController`、`AccountController`（资料/密码/数据权利）、`InvitationController`。
- 馆内管理 `App\Controllers\Manage`：`MemorialController`（创建/概况/资料/设置/发布暂停）、`ContentController`（生平/文章/媒体/可见性）、`MemberController`（成员与邀请）、`ModerationController`（留言/祭奠审核）、`VerificationController`（身份核验）、`AiController`（AI 工作室）。
- 平台后台 `App\Controllers\Admin`：`Dashboard`、`Memorial`（暂停/恢复）、`Moderation`、`Report`、`Claim`、`DataRequest`、`Service`、`User`、`Log`、`Verification`、`AiService`、`AiJob`。
- 领域服务：`MemorialService`（声明、校验、创建、发布、暂停）、`AiStudioService`（四项 AI 任务）、`Ai\AiClientFactory`、`DemoSeeder`。

## 审计

敏感操作（登录、创建/更新/发布/暂停纪念馆、成员变更、内容审核、举报处理、后台处置）调用：
`Audit::log('action.name', 'target_type', $id, ['key' => 'value']);`

## 文案与产品约束

- 语气庄重、克制、具体；避免营销化、游戏化、排行榜、积分、虚拟香火消费暗示。
- 不展示“香火排行榜”“祭奠值”“贡献榜”等设计。
- 默认隐私优先：公开收录需馆主主动勾选；私密馆仅成员可见。
- 每个纪念馆页提供举报入口；后台提供举报/申诉/争议处理。
- 商业化只围绕可交付的整理服务，不售卖虚拟祭品。

## 视觉基线

设计语言为宣纸暖白、墨色、苔绿与克制的朱红印章；全部样式在 `public/assets/css/app.css`，无外部字体/CDN。

公共与管理端共用语义类：`shell`、`section`（`--tint/--tight`）、`eyebrow`、`button`（`--ghost/--small/--wide/--danger`）、`card`、`notice-card`、`grid`（`--2/--3/--4`）、`field`、`form`、`muted`、`flash`、`table`/`table-wrap`、`pager`、`tabs`、`banner`、`chip`、`empty`、`divider`。

管理端布局使用 `manage-shell` + `manage-sidebar`/`manage-main`；后台使用 `admin-shell` + `admin-nav`/`admin-main`。移动端侧栏隐藏，管理操作仍应保证有返回入口；表单控件触控高度不低于 44px。

## 测试与运维

- `./bin/test.sh`：重建 `jidian_test`、写入演示数据、启动 8110、执行 `tests/run.php`（覆盖权限、审核、XSS、私密媒体、上传等）。
- `php bin/doctor.php`：检查运行环境、配置、目录权限、数据库与关键数据。
- `php bin/backup.php`：数据库 + 上传目录备份，默认保留 14 份。
- 部署、Nginx/PHP 配置、安全与合规见 `docs/deployment.md`、`docs/security.md`、`docs/compliance.md`。
