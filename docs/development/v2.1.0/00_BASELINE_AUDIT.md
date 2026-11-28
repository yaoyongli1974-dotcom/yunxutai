# 云叙台 v2.1.0 — Phase 0 基线审查报告

审计基准 commit：`49fc14e94adeadcf4476873f8e19413ac16a30f9`（tag `v2.0.1`）
审计分支：`feature/v2.1.0-production-hardening`
审计日期：2026-11-28
执行方式：6 路只读审查（架构/路由、权限与会话、媒体文件、AI 并发、数据库与 CI、前端与可观测性），交叉验证后汇总。**本阶段未修改任何业务代码、配置、测试或数据库。**

标注约定：**CONFIRMED**（代码已核实）/ **NEEDS_VERIFICATION**（需进一步验证）/ **PROPOSED**（计划方案，待批准）/ **OUT_OF_SCOPE**（本轮不处理）。

---

## 1. Git 与仓库状态

| 项目 | 结果 | 标注 |
|---|---|---|
| 指定基线 commit | `49fc14e94adeadcf4476873f8e19413ac16a30f9` | CONFIRMED |
| 实际 HEAD（审查开始时） | 与指定基线一致 | CONFIRMED |
| 工作区 | `git status --short` 为空，无未提交修改 | CONFIRMED |
| 最近 tag | `v2.0.1`（指向基线），另有 `v2.0.0`、`v2.0.0-rc1`、`v1.0.0` | CONFIRMED |
| 独立工作分支 | 已从 main 建立 `feature/v2.1.0-production-hardening`，未向 main 提交 | CONFIRMED |
| 受控文件总数 | 377 | CONFIRMED |
| 远程 main | 基线提交已通过 CI run `46112214349`（4/4 矩阵全绿）发布 | CONFIRMED（来自发布记录） |

结论：基线正确、工作区干净，满足任务书 0.1 要求。若后续发现 HEAD 变化须重新建立基线。

## 2. 审查方法与证据口径

- 只读取仓库当前 commit 的源码、配置、迁移、CI、测试与已有交付文档。
- 每条问题必须给出 `文件:行号` 证据；无证据的一律列为 **NEEDS_VERIFICATION**，不写作“已确认漏洞”。
- 对任务书示例接口/字段（如 `Policy::canViewMemorial`、`fact_id` 等）只作目标形态参考，不假设其已在代码中存在。
- 本阶段产出的四份文档是唯一允许新增的文件：`00_BASELINE_AUDIT.md`、`01_ARCHITECTURE_MAP.md`、`02_RISK_REGISTER.md`、`03_IMPLEMENTATION_PLAN.md`。

## 3. 实际项目结构与文件清单

| 目录 | 文件数 | 主要内容 | 标注 |
|---|---:|---|---|
| `app/` | 209 | 18 个 Web Controller、6 个 Manage、13 个 Admin、10 个 Api Controller（含 Base）、24 个 Core + 6 个 Kv、19 个 Model、13 个 Service + 4 个 Ai Service、86 个 View | CONFIRMED |
| `bin/` | 11 | `ai-worker.php`、`ai-workflow-worker.php`、`backup.php`、`doctor.php`、`install.php`、`migrate.php`、`migrate-v2.php`、`restore-check.php`、`secret-scan.sh`、`seed.php`、`test.sh` | CONFIRMED |
| `database/` | 29 | `schema.sql`（1033 行 / 56 表）、`20261027_verification.sql`、27 个 `20261127_001`…`027` 迁移 | CONFIRMED |
| `miniprogram/` | 61 | 12 个页面（每页 js/json/wxml/wxss）、`app.js/json/wxss`、`config.js`、`utils/{api,auth,media,request}.js` | CONFIRMED |
| `public/` | 8 | `index.php`、`.htaccess`、4 个 JS、`app.css`、`og-cover.png` | CONFIRMED |
| `tests/` | 3 | `run.php`、`concurrency/rate.php`、`Support/MockRespServer.php` | CONFIRMED |
| `.github/` | 1 | 唯一 workflow `workflows/ci.yml` | CONFIRMED |

任务书点名的核心类 `Auth`、`ApiAuth`、`Policy`、`Db`、`Storage`、`MediaSign`、`Request`、`Response`、`Audit`、`Settings`、`Csrf`、`RateLimiter`、`RateLimitKeys`、`Kv`、`CounterStore`、`MysqlCounter`、`RedisCounter` 均实际存在（CONFIRMED）。`app/Models/Archive.php` 不存在，归档逻辑在 `app/Services/ArchiveService.php`（CONFIRMED）。

不存在但常见于标准框架的目录/文件（非缺陷，仅记录）：`phpunit.xml`、`.env.testing`、`Dockerfile`、`docker-compose.yml`、`Makefile`、`tests/Unit|Feature|Integration/`、`app/Jobs|Events|Listeners|Middleware/`、`database/seeders|factories/`、`miniprogram/pages/index/`、`public/uploads/`（上传实际位于 `storage/uploads/`，见 `app/Core/Storage.php:8-12`）。`deploy/` 实际存在 `nginx.conf`、`php.ini`、`ai-workflow-worker.service`（CONFIRMED，与早期文档“无 service 文件”的描述不同，以当前代码为准）。

## 4. 路由与模块职责

- Web：`app/routes.php` 共 **184** 条（GET 79 / POST 105）；中间件 `auth` 119、`admin` 39、`guest` 6、无中间件 20。无路由分组、无 catch-all（`app/Core/Router.php:11-35,79`）。
- API：`app/routes-api.php` 共 **57** 条（GET 24 / POST 33），全部经 `Api::route()` 包装（`app/Api/Api.php:79-89`）；`api-auth` 50、无中间件 7。
- 详细路由与模块映射见 `01_ARCHITECTURE_MAP.md`。

核心服务职责（CONFIRMED，依据类名、docblock 与调用点）：`AiStudioService` 处理旧版同步 AI 任务；`AiWorkflowService` 处理 2.0 家族记忆工作流并强制人工复核；`ArchiveService` 统一数字归档逻辑与授权；`FamilyService`/`FamilyPersonService`/`GenealogyService`/`FamilyTimelineService` 分别处理家族、人物、关系与事件；`MemorialService` 处理纪念馆创建与认领声明；`InstitutionService` 为平台管理员机构隔离；`NotificationService` 站内优先、邮件尽力而为。

## 5. 核心数据表与既有权限机制

- 56 张表；关键约束包括 `families.uq_family_slug`（`database/schema.sql:448-466`）、`family_members.uq_family_user` (`:469-485`)、`family_persons.deleted_at/verification_status` (`:510-533`)、`person_memorial_links.uq_person_memorial` (`:536-553`)、`archives.status/deleted_at` (`:633-654`)、`archive_files.uq_archive_storage_key/sha256` (`:657-672`)、`ai_*` 工作流与事实表 (`:728-862`)。
- `Policy` 现有能力：`memberRole/role/canView/canEdit/canManage/canModerate/canPost/canViewMedia/isIndexable`（`app/Core/Policy.php:11-98`）；家族角色能力表 `FAMILY_ROLES/FAMILY_CAPABILITIES` (`:108-111`)、`familyRole/can/require/canViewResource/clearCache` (`:146-294`)。`require()` 拒绝时返回 404（`:226`）。
- `ApiAuth::resolve()` 解析 Bearer Token 并调用 `Auth::setApiUser()` 注入（`app/Core/ApiAuth.php:41`，`app/Core/Auth.php:36`）。**CONFIRMED（本轮复核）**：API 中间件 `api-auth` 只接受 Bearer Token，失败返回 JSON 401（`app/Core/Router.php:97-99`），**不存在早期 Round2 报告所述的 Web Session 回退**；`api-admin` 中间件虽定义（`:100-104`）但当前路由未使用。以当前代码为准，不重做该历史问题。
- 媒体签名（Phase B 后）：`MediaSign::url/verify()` 使用 `app.key` 对 `media:{id}:{exp}:{visibility}` 做 HMAC-SHA256，默认 300 秒，绑定可见性使授权下调后旧签名失效（`app/Core/MediaSign.php`）。

## 6. AI Worker 与并发现状（本轮 AI lane 已复核）

- `bin/ai-worker.php` 为旧链路（`AiJob::queued()` + `AiStudioService::process()`），不处理 2.0 工作流任务；`bin/ai-workflow-worker.php` 为 2.0 执行器，CLI、`--limit`、hostname 派生 worker id，SIGALRM 每 60s 续租。
- 工作流状态：`queued|processing|completed|failed|cancelled`（`20261127_017_create_ai_workflows.php:12`）；步骤 `queued|processing|completed|failed|skipped`（`018:13`），但**没有任何代码把步骤设为 processing**，成功/失败时批量标记（`AiWorkflowService.php:257-262,296-300`）。
- 领取：`claimStrict()` 在事务内用 `SELECT ... FOR UPDATE SKIP LOCKED`（`AiWorkflowService.php:108-114`）加条件 UPDATE（`:123-131`），并递增 `lock_version`；CLI Worker 保存 `[id, lock_version]` 并通过 `owns()` 做 fencing（`:165-171,252,273-276,311-314`）。租约 900s，心跳 UPDATE 要求 `lease_until > NOW()`（`:152-158`）。
- **CONFIRMED 缺陷**：Web `runWorkflow()` 调用 `process($id,'web-...')` 不传版本（`AiWorkshopController.php:92`），写入退化为无条件 `id=?`（`AiWorkflowService.php:273-276,311-315`），绕过 fencing；`heartbeat()` 在 UPDATE 失败时回退到 `owns()`，而 `owns()` 不检查租约过期（`:150-171`）。
- **CONFIRMED 缺陷**：迁移 `20261127_027_ai_workflow_locking.php:8-12` 新增 `lock_version/heartbeat_at/last_error_at`，但 `database/schema.sql:728-757` 缺少这三列；`AiWorkflowService.php:109,127,155,190,315` 与 `bin/ai-workflow-worker.php:32,96,104` 已依赖它们；`bin/install.php:65-76` 只执行 schema.sql，`bin/test.sh:22-26` 随后执行 v2 迁移才掩盖该漂移。
- **CONFIRMED 缺陷**：重试分类忽略 Provider 的 `retryable` 标记（`AiWorkflowService.php:289-295` vs `ProcessingClient.php:214-221,237-240`）；撤销同意不取消进行中的 `processing` 任务且漏掉 `failed`（`:742-747`），`run()` 不复核同意（`:585-586`）；`recoverExpired()` 会把 `failed` 重新入队（`:209-221`），存在撤销后重新执行风险。
- 来源追踪：`memory_facts` 有 `source_type/source_id/source_location/confidence`（`20261127_019:14-17`），但 `addFactCandidate()` 未写 `source_location`（`:380-390`）；AI 不直接写入 confirmed 事实，人工复核用 CAS（`:407-434`）。

## 7. 测试、CI 与运维现状

### 7.1 测试体系（CONFIRMED）
- 自建 harness：`tests/run.php:44-63` 的 `test()/check()`，计数 `:40-42`，汇总退出 `:3369-3375`；共 **128** 个 `test()`。
- 真实数据库（非全 mock）：bootstrap `:11`，`bin/test.sh:9,22` 创建全新 `jidian_test`，HTTP 经 `php -S` + curl；仅 Redis 使用 `tests/Support/MockRespServer.php`。
- 覆盖分组：单元测试 5、公开页面 12、账号与权限 14、互动与审核 4、隐私与媒体鉴权 5、举报与服务 3、上传安全 1、小程序 API 11、2FA 4、KV/Redis 6、邮件 6、阶段 B 7、阶段 C 8、阶段 D/E/F 15、阶段 G 4、补齐 4、Round2 Phase1 4 / Phase2 5 / Phase3 4 / Phase4 3 / Phase5 3。
- 缺口：`tests/run.php:2164-2188` 的迁移断言只检查 `001-006` 与 checksum 非空，未覆盖 `007-027`；backup/restore-check/doctor 未被测试调用；`bin/test.sh:38-43` 等待服务但失败不阻断；`TEST_DB` 可删除任意指定库（需限定测试环境）。

### 7.2 CI（CONFIRMED）
- 唯一 workflow `.github/workflows/ci.yml`：push main + 所有 PR；job `tests`，`fail-fast: false`，4 矩阵（PHP 8.4/8.5 × `mysql:8.4`/`mariadb:11`，`:9-22`），服务容器无原生 health check，自定义 PDO 重试 90 次（`:60-74`）。
- 步骤顺序（`:43-110`）：checkout@v6 → setup-php（扩展 pdo_mysql/mbstring/gd/curl/zip，coverage none）→ setup-node@v6（Node 24）→ apt ffmpeg → DB wait → gitleaks@v3 → `bin/secret-scan.sh` → PHP lint → 小程序 `node --check` → composer install + PHPStan（level 5，`phpstan.neon:5`）→ `bin/test.sh` → `migrate-v2.php --status` → 迁移重复执行 → doctor → 最终 git-grep 密钥检查。
- 无备份/恢复演练步骤；`composer.json:6-9` 无运行时依赖，dev 依赖 `phpstan/phpstan ^2.0`，baseline 28 条（不得扩大）。

### 7.3 备份、恢复与 Doctor（CONFIRMED）
- `bin/backup.php:59-127`：mysqldump `--single-transaction --quick --skip-lock-tables` + gzip + tar `storage/uploads`/`storage/verifications` + `manifest.json`；**manifest 无 checksum、无加密**（`:97-102`），保留 14 份。
- `bin/restore-check.php:9-15`：仅 gunzip 后统计 `CREATE TABLE`/`INSERT INTO`，退出码只取决于 `tables > 0`；不导入数据库、不校验行数和文件、不比对 manifest checksum。
- `bin/doctor.php:119-130` 必需表仅 9 张旧表，不覆盖 v2 表与 `schema_migrations` 状态；警告不阻断；`:103-113` 会创建缺失目录。
- 文档曾据 restore-check 声称“结构与数据完整”，但完整恢复演练未做（`docs/development/yunxutai-2.0-round2/05_RELEASE_READINESS_REPORT.md`，未决项）。

## 8. 本阶段已确认问题摘要（证据详见 02_RISK_REGISTER.md）

| ID | 问题 | 严重度 | 证据 |
|---|---|---|---|
| C-01 | `schema.sql` 与迁移 027 列漂移，全新安装缺少 `lock_version/heartbeat_at/last_error_at` | P1（可导致 AI 工作流 SQL 错误） | `20261127_027...:8-12` vs `schema.sql:728-757`、`install.php:65-76` |
| C-02 | 签名媒体 URL 在 `APP_KEY` 缺失时可用已知回退密钥伪造；端点无授权复核 | P0（部署条件触发） | `config/config.php:4-7`、`MediaSign.php:36-39`、`Api/Controllers/MediaController.php:22-35` |
| C-03 | 已批准归档删除只改状态，物理文件与 `archive_files` 行未清理 | P1（数据治理/隐私） | `ArchiveService.php:467-493`、grep 无 cleanup |
| C-04 | `ArchiveService::addFile()` 的 `catch (Throwable)` 未导入全局类，补偿逻辑为死代码 | P2 | `ArchiveService.php:4-10,210` |
| C-05 | 私有媒体 `Cache-Control: private, max-age=300` 使授权变更在浏览器中延迟生效 | P2 | `Controllers/MediaController.php:42`、`Api/Controllers/MediaController.php:48` |
| C-06 | `restore-check.php` 仅校验 gzip 与 ≥1 CREATE TABLE，不能证明恢复成功 | P1（验收可信度） | `bin/restore-check.php:9-15` |
| C-07 | Doctor 不检查 v2 表与迁移状态；迁移测试只覆盖 001-006 | P1（门禁覆盖） | `bin/doctor.php:119-130`、`tests/run.php:2166-2171` |
| C-08 | `20261027_verification.sql` 不在版本化 runner 内且自声明非幂等 | P2 | `migrate-v2.php:43`、该文件 `:2-3` |

前端/小程序/可观测性 lane 补充确认（详见 `02_RISK_REGISTER.md` R-24~R-30）：无 `request_id`；`APP_DEBUG` 默认 true 且 debug 路径暴露堆栈；API Token 签发无审计、审计 meta 含邮箱/姓名/异常原文；无批量上传与新手引导；小程序 12 页缺人物/谱系/时间线/展览/AI/邀请接受页且 `ensureSession()` 无调用；Nginx 边缘无 CSP。

> 所有 lane 已完成交叉验证，最终问题清单以 `02_RISK_REGISTER.md` 为准；本表仅摘要。

## 9. 待验证风险（不得当作已确认）

- `api-auth` 的 Web Session 回退是否在最终代码仍存在、是否导致 API 身份混用（`app/Core/Router.php:97`；需以本轮权限 lane 结果为准）。
- 真实 Redis Lua 原子限流与真实多 Worker 并发领取；当前仅 mock RESP 与子进程模拟。
- MariaDB 11 与 MySQL 8.4 对所有 27 个迁移的 DDL/ENUM 行为差异，需双引擎实跑。
- APP_KEY 回退在真实部署中的取值与 Nginx/Cookie 安全配置，需人工确认生产环境变量。
- polyglot 上传、文件名头注入、storage_key 编码绕过、签名 URL 撤销后即时访问，均需针对性测试。
- 生产环境的季度恢复演练是否真实执行（文档要求但无证据）。

## 10. 本轮不处理 / 需人工决策

- **OUT_OF_SCOPE**：生产服务器、生产数据库、真实 AI 密钥、支付配置、DNS、Nginx 生产配置、线上服务关闭、自动发版与自动 push main（任务书 13.5 明确禁止，除非另行授权）。
- **需人工决策**：
  1. 目标版本最终定为 v2.1.0 还是降为 v2.0.2（取决于 P0/P1 验收结果与是否引入迁移）。
  2. 归档删除后物理文件是“批准即清理”还是“进入待清理队列 + 独立任务清理”，涉及保留义务。
  3. AI 生成事实的来源追踪是否新增字段/迁移，或复用现有 `memory_facts` 字段（需业务确认语义）。
  4. API 是否允许 Web Session 访问；若禁止，小程序与 Web 是否有依赖该回退的调用方。
  5. 私有媒体缓存策略改为 `no-store` 是否影响现有前端体验与 CDN 配置。

## 11. Phase 0 门禁结论

- Git 基线、工作区、分支隔离：通过。
- 四份审查文档已产出；未修改业务代码：通过。
- 已确认问题均有代码证据；未取得证据的项目明确列为待验证：通过。
- **不得进入 Phase B，直到项目负责人确认本报告与 `03_IMPLEMENTATION_PLAN.md` 的修复范围、版本决策与需人工决策项。**
