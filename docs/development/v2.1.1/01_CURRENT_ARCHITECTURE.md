# v2.1.1 Phase 0 — 当前架构与 API 调用链

基线：`e723d07`（`ff23bc5` + `ModerationController.php` 注释错字修复，无行为差异）。分支：`feature/v2.1.1-production-hardening`。
标注：**CONFIRMED**（代码核实）/ **INFERENCE**（推理）。

## 1. 目录与模块规模（CONFIRMED）

| 目录 | 文件 | 子目录 | 主要职责 |
|---|---:|---:|---|
| `app/` | 209 | 32 | 顶层 `bootstrap/error-handler/helpers/routes/routes-api.php` |
| `app/Core/` | 31 | 2 | `Router`、`Request/Response`、`Auth/ApiAuth`、`Policy`、`Db`、`Audit`、`Storage`、`RateLimiter/RateLimitKeys`、`Settings`、`Csrf`、`Notify`、`View`、`Kv/`（7 个实现/接口） |
| `app/Controllers/` | 37 | 3 | Web 23、`Admin/` 14、`Manage/` 7 |
| `app/Api/` | 15 | 2 | `Api/ApiException/Input/Presenter/WechatLogin`、`Controllers/` 10 |
| `app/Services/` | 16 | 2 | `AiWorkflowService`、`ArchiveService`、`AiStudioService`（legacy）、领域服务、`Ai/` 4 |
| `app/Models/` | 19 | 1 | 静态 `Db` 薄封装，无 ORM |
| `app/Views/` | 86 | 21 | PHP 模板 |
| `bin/` | 12 | 1 | `ai-worker.php`（legacy）、`ai-workflow-worker.php`、`archive-cleanup.php`、`install/migrate/migrate-v2/seed/backup/restore-check/doctor/secret-scan/test` |
| `database/` | 34 | 2 | `schema.sql`（56 表）+ 33 个迁移（`20261127_001…027`、`20261128_001…005`） |
| `miniprogram/` | 85 | 19 | 21 个页面 × 4 文件 + `app.*`、`config.js`、`utils/{api,auth,media,request}.js` |
| `public/` | 9 | 4 | `index.php` 单入口、`.htaccess`、`assets/` |
| `tests/` | 4 | 3 | `run.php`、`concurrency/{claim-once,rate}.php`、`Support/MockRespServer.php` |

关键架构事实：**v2 AI 工作流栈**（`ai_workflows` + `AiWorkflowService`）与 **legacy AI 栈**（`ai_jobs` + `AiJob` + `AiStudioService` + `bin/ai-worker.php`）并存（`bin/ai-workflow-worker.php:6` 明确注释）。本轮状态机改造聚焦前者，后者仅做兼容审查，不混为一谈。

## 2. 路由与鉴权（CONFIRMED）

- Web `app/routes.php`：79 GET + 105 POST = **184 条**；中间件 `auth` 119、`admin` 39、`guest` 6，公开 20。
- API `app/routes-api.php`：24 GET + 33 POST = **57 条**；`api-auth` 50，公开 7。
- 中间件实现 `app/Core/Router.php:84-105`：`auth`（重定向 `/login?next=`）、`guest`、`admin`（403/登录）、`api-auth`（**仅 Bearer，无 Session 回退**）、`api-admin` 已定义但**未被任何路由使用**。
- Web 与 API 共用 `public/index.php:10-20` 单一 Router；API 端点统一经 `Api::route()`（`app/Api/Api.php:81`）。

### 2.1 AI 工作流路由
Web（`routes.php:181-190`，全部 `auth`）：`GET /families/{id}/ai`、`POST /families/{id}/ai/workflows`、`GET/POST /families/{id}/ai/workflows/{jobId}[/run|/cancel]`、`POST /ai/facts/{id}/review`、口述会话与片段相关 4 条。
Legacy AI：`routes.php:142-145`（manage 4 条）、`255-264`（admin services/settings/orders/jobs）。
API（`routes-api.php:65-67,82-92`，全部 `api-auth`）：工作流详情/取消/事实复核、创建、口述会话 8 条、片段纠正/复核。

### 2.2 归档路由
Web（`routes.php:208-221`，全部 `auth`）：列表/新建/详情/编辑、文件上传、标签/人物关联、照片标签复核、导出、删除请求/审批（14 条）。
API：`GET /api/v1/archives/{id}`、`GET/POST /api/v1/families/{id}/archives`（共 3 条）。**下载、删除请求与审批为 Web-only**（CONFIRMED）。

### 2.3 审核 / 数据请求 / 邀请
- 管理端审核（`routes.php:234-240`，`admin`）：`GET /admin/moderation` + 3 条 batch + 3 条单条状态。
- 馆主级审核（`routes.php:175-177`，`auth`）：`GET /manage/memorials/{id}/moderation` + 消息/贡献状态。
- 数据请求：`POST /account/data-request`（`routes.php:88`）、`GET/POST /admin/data-requests[/{id}]`（`routes.php:249-250`）。
- 邀请：Web `routes.php:97-98,108,111,170,173`；API `routes-api.php:62,74,56`。

## 3. 核心调用链（CONFIRMED）

### 3.1 AI 工作流
- **创建**：`AiWorkshopController::createWorkflow`（`app/Controllers/AiWorkshopController.php:47-61`）→ `Csrf::verify` → private `family()`（`:21-29`，`FamilyService::find` + `Policy::require`）→ `AiWorkflowService::create`（`app/Services/AiWorkflowService.php:35-94`：`Policy::require`、`assertInputAuthorized`、幂等哈希、`Db::transaction` 写 `ai_workflows` + `ai_workflow_steps`、`Audit::log`）。API 入口 `FamilyV2Controller::createWorkflow`（`:251-267`）→ 限流 → 同一 service。
- **执行**：`AiWorkshopController::runWorkflow`（`:81-98`）→ `AiWorkflowService::process`（`:282-401`：`owns`/`claim` → `assertRunAuthorized` → `run`（`AiClientFactory`/`ProcessingClient`）→ `Db::transaction` 写步骤 `output_ref='pending_review'`、工作流 `completed`、`ai_generation_logs` → 通知）。
- **Worker**：`bin/ai-workflow-worker.php:68` `recoverExpired()` → `:78` `claimStrict()`（`FOR UPDATE SKIP LOCKED` + fencing token，`:121-160`）→ `:104` `process()` → `:55` `heartbeat()`。
- **取消**：`AiWorkflowService::cancel`（`:403-427`）递增 `lock_version`，步骤 `queued|failed`→`skipped`。
- **事实复核**：`AiWorkflowService::reviewFact`（`:504-536`）使用 `memory_facts.verification_status` 乐观 CAS。
- **Legacy**：`AiJob` / `AiStudioService::process` / `bin/ai-worker.php` 独立链路，不在上述工作流中。

### 3.2 归档
上传 `ArchiveController::upload`（`:136-157`）→ `RateLimiter::hit` → `ArchiveService::addFile`（`:185-219`）→ `Storage::storeArchiveUpload` → `archive_files` + 审计（DB 失败补偿 unlink）。
下载 `ArchiveController::download`（`:159-182`）→ `ArchiveService::getFileVisible`（`:235-258`，`Policy::can('file.download')`）→ `absolutePath`（`:260-268`）→ `Response::file`。
删除请求/审批 `requestDelete`（`:251-263`）/ `approveDelete`（`:467-500`）→ `INSERT IGNORE` 入 `archive_cleanup_queue`，**不物理删除**；`bin/archive-cleanup.php:35-66` 闭包领取（`FOR UPDATE SKIP LOCKED`、attempts++），`:69-129` 复核 `archives.status='deleted'`、共享 `storage_key` 保护、`Storage::delete`、删 `archive_files`、队列 done 并审计。
导出 `ArchiveController::export`（`:241-249`）→ `ArchiveService::exportBundle`（`:408-433`）。

### 3.3 审核与数据请求
- `Admin\ModerationController::messageStatus/:articleStatus/:mediaStatus`（`:27/:54/:96`）调用 Model 状态变更 + `Audit::log`；batch 三方法（`:132/:175/:232`）→ `parseIds`（`:287+`）→ `WHERE id IN (...)` 一次预加载（`:150-154,198-202,250-254`）→ 同 Model 方法（`'batch'=>true` 审计）。`guard()`（`:339`）执行角色校验。
- 馆主路径 `Manage\ModerationController`（`:28/:54`）→ `Policy::canModerate` → `Message::setStatus`/`Tribute::setStatus`。
- `AccountController::requestData`（`:191-235`）→ `RateLimiter::hit` → `DataRequest::create` + 审计；`Admin\DataRequestController::handle`（`:36-103`）→ `updateStatus` + 审计 + 通知，**不做物理删除**（`:101` 明确）。

## 4. 前端与小程序（CONFIRMED）

- Web：`app/Views/ai/index.php:53-127` 渲染工作流类型/状态/`current_step`/retry、事实复核、会话授权与片段审核；`ai/show.php:16-44` 展示状态、步骤名/状态、草稿输出；`admin/moderation.php:34-206` 三条批量表单；`manage/moderation.php:80-105` 分组展示；`admin/data-requests.php:26-101` 状态筛选与处理。
- 小程序：`miniprogram/utils/api.js:197-221` 映射 v2 API；`pages/ai/workflow.js:11-22` 定义 `STATUS_LABELS`/`STEP_STATUS_LABELS`，`:91-121` 用确定性幂等键提交 `extract_facts`，`:143-193` 每 2 秒轮询（最多 30 次）并渲染步骤状态/输出/错误；`pages/archives/list.js:6-22`、`pages/invitations/accept.js:38` 调用对应 API。
- **INFERENCE**：小程序无审核与数据请求页面，这两个流程当前为 Web-only（基于页面清单，非运行时验证）。

## 5. 部署与运行时（CONFIRMED）

- `app/bootstrap.php`：`.env` 加载（`:6-24`）、PSR-4 风格自动加载（`:26-34`）、会话 cookie 与可选 Redis handler（`:58-77`）、`Settings::reset/load` 每请求重载（`:79-89`）、安全头与 `X-Request-Id`（`:93-105`）。
- `config/config.php:25-35,106-109`：应用版本回退 `v2.1.0`；存储路径在 `storage/`。
- `public/index.php` 唯一前端控制器（Web+API）；`deploy/nginx.conf:15,37-64` docroot `public/`、拒绝访问 `app/config/database/storage/tests/bin/docs`、静态缓存、FPM socket、边缘 HSTS。
- `deploy/php.ini:9-20`：上传 52M、`max_execution_time=60`、secure/samesite cookie。
- `.env.example:1-65`：DB、邮件（`log|smtp|none`）、AI（`AI_ENABLED/provider/key`、`AI_SYNC=true`）、微信、`REDIS_URL`、`RATE_DRIVER=mysql`。
- `deploy/ai-workflow-worker.service:7-14`：systemd 运行 `php bin/ai-workflow-worker.php --limit=5 --max-runtime=300`，`Restart=always`。
- `bin/archive-cleanup.php:12-14` 明确**无调度器**，需部署 cron（`--once --limit=20 --max-attempts=3`）。
- CI `.github/workflows/ci.yml:13-24,49-110`：4 矩阵（PHP 8.4/8.5 × MySQL 8.4/MariaDB 11）、Node 24、gitleaks、PHP lint、PHPStan level 5、migrations、doctor；`tests/run.php` 为集成套件。

## 6. 架构结论

本仓库不是待设计的新系统，而是**已在 v2.1.0 落地多项加固**（workflow locking、cleanup queue、request-id audit、perf indexes）的生产代码库。v2.1.1 的重点是在既有结构上修正**状态真实性、超时/取消控制、来源与审核可信度、哈希可信度、权限一致性、并发一致性**，而不是重建架构。Phase 0 未修改业务代码。
