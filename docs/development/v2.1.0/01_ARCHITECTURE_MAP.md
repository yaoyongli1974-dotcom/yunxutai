# 云叙台 v2.1.0 — 架构与数据流图（Phase 0）

基线：`49fc14e94adeadcf4476873f8e19413ac16a30f9`（v2.0.1）
说明：本文只描述当前代码的实际结构，不表示目标设计。行号均指该 commit。

---

## 1. 系统分层

```
浏览器 / 小程序
      │
      ▼
public/index.php ── 注册 Web 路由(routes.php) 与 API 路由(routes-api.php) 到同一个 Router
      │
      ├─ app/Core/Router.php       路由匹配、中间件(auth/admin/guest/api-auth/api-admin)
      ├─ app/Api/Api.php           API 包装层：Api::route() 统一 JSON 输出/异常处理 (:79-89)
      │
      ▼
Controller 层
  ├─ app/Controllers/       18 个 Web 控制器（页面 + 表单动作）
  ├─ app/Controllers/Manage/ 6 个（馆主管理：memorial/content/member/ai/verification/moderation）
  ├─ app/Controllers/Admin/  13 个（平台管理员）
  └─ app/Api/Controllers/    10 个（含 Base；FamilyV2 承担家族/人物/档案/AI/通知 API）
      │
      ▼
Service 层（app/Services/）
  ├─ 业务：Family / FamilyPerson / Genealogy / FamilyTimeline / Memorial / Archive / Exhibition / Notification / Institution
  └─ AI：AiStudioService（旧链路）、AiWorkflowService（2.0 工作流）、Ai/AiClient + Factory + OpenAiClient + ProcessingClient
      │
      ▼
Core 层（app/Core/）
  Auth / ApiAuth / Policy / Db / Storage / MediaSign / Request / Response / Audit / Settings /
  Csrf / RateLimiter / RateLimitKeys / Kv + Kv/{CounterStore,MysqlCounter,RedisCounter,RespClient,RedisSessionHandler}
      │
      ▼
存储
  ├─ MySQL 8.4 / MariaDB 11：56 表，schema.sql + 27 个版本化迁移
  ├─ 文件：storage/uploads/、storage/verifications/（Web 根目录之外，config/config.php:89-93；deploy/nginx.conf:15,31-38,58）
  ├─ Redis（可选）：限流计数、Session handler
  └─ 日志/备份：storage/logs/、storage/backups/
```

CLI 入口：`bin/ai-worker.php`（旧 AI 任务）、`bin/ai-workflow-worker.php`（2.0 工作流）、`bin/migrate.php`（旧）、`bin/migrate-v2.php`（版本化）、`bin/backup.php`、`bin/restore-check.php`、`bin/doctor.php`、`bin/test.sh`。

---

## 2. 路由清单

### 2.1 Web 路由（`app/routes.php`，184 条）

| 分组 | 行号范围 | 中间件 | 代表路径 / 说明 |
|---|---|---|---|
| 公开首页与纪念馆浏览 | L48-59 | 无 | `/`、`/memorials`、`/m/{slug}`（gallery/life/articles/tributes/messages）、`/media/{id}`、`/sitemap.xml`、`/robots.txt` |
| 静态页与服务 | L61-70 | 无 | `/about`、`/privacy`、`/terms`、`/services`（GET/POST）、`/report`、`/report/success` |
| 认证 | L73-79 | guest/auth | `/register`、`/login`、`/2fa`、`/logout` |
| 账户 | L82-88 | auth | `/dashboard`、`/account`、`/account/profile`、`/account/password`、`/account/totp/*`、`/account/data-request` |
| 互动与认领 | L91-94 | auth | `/m/{slug}/tributes`、`/m/{slug}/messages`、`/m/{slug}/claim` |
| 邀请 | L97-98, L111 | auth | `/invitations/{token}`、`/invitations/{invitationId}/revoke` |
| 家族 | L101-112 | auth | `/families`、`/families/{id}`、`/members/invite`、`/members/{userId}/role|remove`、`/leave` |
| 人物 | L114-123 | auth | `/persons`、`/persons/{personId}`、`/persons/merge`、`/persons/{personId}/memorial|branch` |
| 谱系 | L125-129 | auth | `/families/{id}/genealogy`、`/genealogy/relationships`、`/relationships/{id}/review|revoke`、`/genealogy/branches` |
| 管理端（馆主） | L132-173 | auth | `/manage`、`/manage/memorials/*`、`/profile`、`/settings`、`/verification`、`/ai`、`/ai/jobs/*`、`/life`、`/articles`、`/media`、`/members` |
| 内容审核（馆主） | L175-177 | auth | `/manage/memorials/{id}/moderation`、消息/文章/媒体状态 |
| AI 工作台 | L181-190 | auth | `/families/{id}/ai`、`/ai/workflows/*`、`/ai/facts/{id}/review`、`/ai/oral-sessions/*`、`/ai/segments/{id}/review` |
| 时间线/展览/通知 | L193-205 | auth | `/families/{id}/timeline`、`/families/{id}/exhibitions`、`/exhibitions/{id}`、`/notifications/*` |
| 档案 | L208-221 | auth | `/families/{id}/archives`、`/archives/new`、`/archives/files/{id}/download`、`/archives/{id}`、edit/upload/tags/persons/export/delete-request/decision |
| 平台管理 | L223-270 | admin | `/admin`、`/admin/memorials`、`/admin/verifications`、`/admin/moderation`、`/admin/reports`、`/admin/claims`、`/admin/data-requests`、`/admin/services`、`/admin/ai-*`、`/admin/users`、`/admin/logs` |

统计：GET 79 / POST 105；中间件 `auth` 119、`admin` 39、`guest` 6、无中间件 20。路由系统仅 `get/post`（`app/Core/Router.php:11-35`），不匹配时 404/405（`:79`），无路由分组与 catch-all。

### 2.2 API 路由（`app/routes-api.php`，57 条）

全部经 `Api::route()` 包装（`app/Api/Api.php:79-89`）。统计：GET 24 / POST 33；`api-auth` 50、无中间件 7。

| 分组 | 行号 | 中间件 | 路径 |
|---|---|---|---|
| 认证（公开） | L23-24 | 无 | `POST /api/v1/auth/token`、`POST /api/v1/auth/wechat` |
| 认证（受控） | L27-28 | api-auth | `POST /auth/logout`、`GET /me` |
| 媒体 | L31 | 无 | `GET /api/v1/media/{id}`（MediaSign 签名 URL） |
| 纪念馆与互动 | L34-48 | 混合 | `GET /memorials`、`GET /memorials/{slug}`、`GET /memorials/{slug}/articles/{id}`、`GET /memorials/{slug}/messages`、`POST .../tribute|message|claim`、`POST /reports` |
| 管理端 | L48-56 | api-auth | `GET|POST /manage/memorials`、`GET /manage/memorials/{id}/members`、`POST /members/invite|role|remove`、`POST /invitations/{id}/revoke` |
| FamilyV2 | L60-97 | api-auth | `GET|POST /families`、`GET /families/{id}`、`/families/{id}/members|persons|genealogy|archives|events|exhibitions|ai/workflows|ai/sessions`、`/persons/{id}`、`/archives/{id}`、`/ai/workflows/{id}`、`/ai/sessions/{id}`、`/ai/segments/{id}`、`/notifications`、`/exhibitions/{id}/publish` 等 |

> 复核结论：中间件 `api-auth` **只接受 Bearer Token**（`app/Core/Router.php:97-99`），无 Web Session 回退；早期 Round2 文档所述回退与当前基线不符，本轮不重做该项。`api-admin` 已定义但无路由使用。

---

## 3. 核心类与职责

| 类 | 关键方法（行号） | 职责 |
|---|---|---|
| `Auth` | `user():?array` (:11)、`setApiUser()` (:36)、`check()` (:42)、`isAdmin()` (:47)、`attempt()` (:52，含 dummy hash 抗计时)、`login()` (:69，session regenerate + CSRF + audit)、`logout()` (:81) | Web 会话身份；注意静态用户缓存与 API 注入边界 |
| `ApiAuth` | `createToken()` (:21，仅存 sha256、30 天)、`resolve()` (:41，Bearer → 用户并注入 Auth、更新 last_used_at)、`revoke()` (:77)、`bearerToken()` (:92) | API Token 身份 |
| `Policy` | `memberRole` (:11)、`role` (:27)、`canView` (:38)、`canEdit/canManage/canModerate/canPost` (:54/:63/:68/:73)、`canViewMedia` (:87)、`isIndexable` (:98)、`FAMILY_ROLES/CAPABILITIES` (:108/:111)、`familyRole` (:146)、`can` (:170)、`require` (:226，拒绝→404)、`canViewResource` (:236，白名单 `RESOURCE_TABLES` :137)、`clearCache` (:294) | 统一资源授权与家族能力映射 |
| `Db` | `query/fetch/fetchAll/value` (:31/:38/:44/:49)、`insert/update` (:55/:68，列白名单 :99)、`transaction()` (:82) | PDO 封装 |
| `Storage` | `uploadsPath` (:8)、`storeUpload` (:19)、`storeArchiveUpload` (:83)、`storeVerificationDocument` (:162)、`storeGeneratedBytes/File` (:246/:272)、`delete/absolute` (:285/:300) | 文件落盘、MIME 白名单、随机名、0640、SHA-256、JPEG 重编码 |
| `MediaSign` | `url()` (:16，默认 900s)、`verify()` (:22，HMAC-SHA256 `media:{id}:{exp}`，`hash_equals`) | 签名媒体 URL |
| `Request/Response` | `fromGlobals/input/raw/int/bool/file/ip/header` (:18-83)；`html/text/json/redirect/file` (:17-50)、`withHeader/status/body/send` (:52-83) | HTTP 抽象；`ip()` 仅取 `REMOTE_ADDR` (:73-77) |
| `Audit` | `log()` (:8，写 `audit_logs`，IP 哈希，失败仅 error_log) | 审计事件 |
| `Settings` | 白名单 (:15-42)、`load()` (:53，DB 覆盖 config)、`get/set/all/reset` (:120/:133/:152/:159) | AI/平台设置 |
| `Csrf` | `token/field/verify` (:8/:16/:21，失败 419) | CSRF |
| `RateLimiter/RateLimitKeys` | `hit()` (:14，key sha256，存储异常 fail-closed)、`blocked` (:26)、`clear` (:31)；`make()` (:11，场景+哈希身份/资源) | 限流 |
| `Kv` / `Kv/CounterStore` | `counter()` (:21，Redis 优先否则 MySQL)；接口 `increment/consume/count/ttl/reset` (:17-38)；`MysqlCounter` 原子 UPSERT `consume` (:33-47)；`RedisCounter` Lua 固定窗口 (:33-50) | 计数与限流存储 |

---

## 4. 服务层与控制器映射

- **Web Controller（18）**：Account、AiWorkshop、Archive、Auth、Dashboard、Exhibition、Family、FamilyPerson、Genealogy、Home、Invitation、Media、Memorial、Notification、Page、Report、Timeline、Tribute。
- **Manage（6）**：Ai、Content、Member、Memorial、Moderation、Verification。
- **Admin（13）**：AiJob、AiOrder、AiService、Claim、Dashboard、DataRequest、Log、Memorial、Moderation、Report、Service、User、Verification。
- **Api（10）**：Auth、Base、Claim、FamilyV2、Manage、Media、Member、Memorial、Report、Tribute。
- **Service（13 + 4）**：AiStudioService、AiWorkflowService、ArchiveService、DemoSeeder、ExhibitionService、FamilyPersonService、FamilyService、FamilyTimelineService、GenealogyService、InstitutionService、MemorialService、NotificationService；`Ai/{AiClient,AiClientFactory,OpenAiClient,ProcessingClient}`。

---

## 5. 关键数据流

### 5.1 Web 请求
```
浏览器 → Router::dispatch → middleware(auth/admin/guest)
      → Controller → Service → Db / Storage / Policy / Audit
      → View(app/Views/*.php, 86 个模板) 或 Response::redirect/json/file
```
异常由 `app/error-handler.php` 统一处理；`HttpException` 映射状态码；错误页 `app/Views/errors/{error,debug}.php`。

### 5.2 API 请求
```
小程序/第三方 → POST /api/v1/auth/token 或 /auth/wechat → ApiAuth::createToken
      → Bearer Token → api-auth 中间件(ApiAuth::resolve → Auth::setApiUser)
      → Api Controller → Service → Db
      → Api::route 包装 JSON 成功/失败
```
当前中间件也接受 Web Session（`Router.php:97`），构成潜在身份混用面（NEEDS_VERIFICATION，待权限 lane 确认）。

### 5.3 媒体上传与访问
```
上传: ContentController@store / ArchiveController@upload
   → Storage::storeUpload/storeArchiveUpload
   → MIME 白名单(config/config.php:57-70) + 20MB/50MB 上限 + 随机 32hex 文件名 + chmod 0640
   → 落盘 storage/uploads/Y/m 或 storage/uploads/archives/Y/m
   → DB: media / archive_files(storage_key, sha256)

访问(Web): GET /media/{id} → Policy::canViewMedia → Storage::absolute → Response::file
访问(API): GET /api/v1/media/{id}?exp=&sig= → MediaSign::verify → 仅检查 status=active（无 Policy 复核）
```
签名 URL 仅绑定 `(id, exp)`，TTL 900s；`APP_KEY` 缺失时回退到字面量 `dev-only-key-change-before-production`（CONFIRMED，见风险 C-02）。

### 5.4 归档与删除
```
创建归档 → ArchiveService::create
上传文件 → addFile → getVisible + Policy::require('archive.upload') → storeArchiveUpload
下载 → GET /archives/files/{id}/download → getFileVisible(Policy::require('archive.view') + file.download)
     → absolutePath(拒绝 .. 与 /) → audit → Response::file(attachment)
删除 → requestDelete(status=delete_requested) → approveDelete(status=deleted, deleted_at)
     → 当前无物理清理、无 archive_files 行清理（CONFIRMED，C-03）
```

### 5.5 AI 工作流
```
Web: /families/{id}/ai → AiWorkshopController → AiWorkflowService::create
API: /families/{id}/ai/workflows → FamilyV2Controller → AiWorkflowService::create
Worker: bin/ai-workflow-worker.php
   → recoverExpired() → claim()（条件 UPDATE + lease_until）
   → process(id, worker) → AiClient/ProcessingClient 外部调用 → addFactCandidate / reviewFact
```
迁移 027 引入 `lock_version/heartbeat_at/last_error_at`，但 `schema.sql` 未同步（C-01）；是否有 fencing token/心跳以 AI lane 最终结论为准。

### 5.6 数据治理与运维
```
迁移: bin/migrate-v2.php --status / 执行：glob *.php + sort + sha256 checksum；失败记录 failed
备份: bin/backup.php → mysqldump + gzip + tar(uploads/verifications) + manifest.json
恢复检查: bin/restore-check.php → gunzip + 统计 CREATE/INSERT（仅 tables>0 决定退出码）
Doctor: bin/doctor.php → PHP/扩展/目录/DB/9 张旧表/AI/Redis/磁盘；警告不阻断
测试: bin/test.sh → install.php --fresh --demo → migrate-v2 → php -S → tests/run.php(128) → migrate status/repeat → doctor
```

---

## 6. 数据库关系骨架

```
users ──< family_members >── families ──< family_persons ──< person_relationships
  │            │                  │                │
  │            │                  ├─< family_branches (自引用 SET NULL)
  │            │                  ├─< family_events ──< family_event_persons
  │            │                  └─< person_memorial_links >── memorials
  │            │
  │            └─ invitations / notifications
  ├─< memorials (visibility/status/verification)
  ├─< media (status/visibility) ── 依赖 Policy::canViewMedia
  ├─< archives ──< archive_files(sha256, storage_key, file_role)
  │        ├─< archive_versions
  │        ├─< archive_tags ──< archive_tag_links
  │        └─< archive_person_links >── family_persons
  └─< ai_orders / ai_jobs / ai_workflows ──< ai_workflow_steps
                ├─< memory_facts
                ├─< oral_history_sessions ──< oral_history_segments
                └─< ai_generation_logs
```
全部 56 表见 `database/schema.sql`；27 个版本化迁移 `20261127_001`…`027` 与 schema.sql 的 CREATE TABLE 主体已做归一化比对，未发现缺失/分歧；但 027 的 ALTER 新增列未回写 schema.sql（C-01）。

---

## 7. 小程序与前端结构

- 小程序页面（12，均在 `miniprogram/app.json:2-15` 声明）：`pages/login/login`、`pages/memorials/list`、`pages/memorial/detail`、`pages/article/view`、`pages/manage/create`、`pages/manage/members`、`pages/report/report`、`pages/account/account`、`pages/families/list`、`pages/families/detail`、`pages/archives/list`、`pages/notifications/list`。
- 共享模块：`miniprogram/utils/{api,auth,media,request}.js`；页面级 js/json/wxml/wxss 各 4 文件。
- Web 视图：`app/Views/` 86 个模板，含 `layout.php`、home、memorial、family、manage、admin、ai、archive、notification、errors、partials 等；空状态有 `partials/empty-state.php`。
- 静态资源：`public/assets/css/app.css`、`public/assets/js/{app,admin-ai-settings,genealogy,photo-tags}.js`。

---

## 8. 可观测性与审计

```
用户操作 → Controller/Service → Audit::log(event, actor, resource, metadata) → audit_logs(IP 哈希)
异常 → HttpException / error-handler → JSON(API) 或 errors/*.php(Web)
日志 → storage/logs/；备份 → storage/backups/；manifest 无 checksum（C-06）
```
`request_id` 贯穿情况、敏感字段脱敏、错误页是否泄漏堆栈/SQL/路径，以 P2 可观测性 lane 结论为准。
