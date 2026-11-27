# 云叙台 2.0 Round 2 — Phase 0 代码基线审计

审计基准 commit：`b5e3511065924cf32e8fa0f09d82434f65409c19`（main）
审计时间：2026-11-28
范围：按任务书第十七部分逐项核对，不修改任何业务代码。

标注约定：**CONFIRMED**（代码已核实）/ **NEEDS_VERIFICATION**（需进一步验证）/ **PROPOSED**（本轮计划改造）。

## 1. Git 与仓库状态

| 项目 | 结果 | 标注 |
|---|---|---|
| 分支 | `main` | CONFIRMED |
| HEAD | `b5e3511065924cf32e8fa0f09d82434f65409c19` | CONFIRMED |
| origin/main | 与 HEAD 相同（`git fetch -q origin` 后核对） | CONFIRMED |
| 未提交修改 | 无（`git status --porcelain` 为空） | CONFIRMED |
| 本轮不推送 | 遵守，仅本地提交审计报告 | CONFIRMED |

## 2. 测试基线

- 命令：`timeout 900 bash bin/test.sh`，重定向日志 `/tmp/opencode/round2-baseline-test.log`。
- 结果：exit=0，**109 通过 / 0 失败 / 0 跳过**。
- 覆盖：旧纪念馆业务、2.0 A~G 全部功能、补齐项（OCR/ASR 配置、照片标注、档案导出、机构隔离）。
- 结论：109 项全绿**不代表**并发安全、认证边界或 SSRF 安全；本轮 Phase 1/2 需新增针对性测试。NEEDS_VERIFICATION 已转化为 PROPOSED 测试项。

## 3. API 认证边界（A-01 / A-02）

- **CONFIRMED**：`app/Core/Router.php:97` 的 `api-auth` 中间件为 `ApiAuth::resolve() !== null || Auth::check()`；`:100` 的 `api-admin` 同样包含 `Auth::check()`。存在明确的 Web Session 回退。
- **CONFIRMED**：`app/routes-api.php` 中 41 处使用 `api-auth`。
- **CONFIRMED**：`ApiAuth::resolve()`（`app/Core/ApiAuth.php:69`）成功时调用 `Auth::setApiUser($row)`；该方法在 `app/Core/Auth.php:36` 存在，无需新增注入点。
- **CONFIRMED**：若 Session 回退命中，API 控制器中的 `Auth::user()` 可能来自 Web 会话而非 Token，与任务书“API 管理员必须用 token 身份”冲突。
- **PROPOSED**：中间件改为仅接受 Bearer Token（保留 `api-auth` 名称），失败统一 JSON 401；保留 Web 路由的 `auth`/`admin` 不变；如确需 Session 访问 API，另设 `api-session-auth` 并强制 CSRF。
- **PROPOSED**：A-02 的 9 项认证/CSRF 测试全部加入 `tests/run.php`。

## 4. 限流实现（B-01~B-04）

- **CONFIRMED**：`app/Core/RateLimiter.php::hit()` 为 `count($key) >= $max ? false : increment($key, $window) <= $max`，先读后写，非原子；`blocked()` 再读一次。
- **CONFIRMED**：`app/Core/Kv/CounterStore.php` 接口只有 `count/increment/reset`；`MysqlCounter.php` 使用 SELECT + UPDATE；`RedisCounter.php:26-28` 使用 `INCR` + 首次 `EXPIRE`，无 Lua。
- **CONFIRMED**：`app/` 中 16 处调用 `RateLimiter::hit`，覆盖 api-token、report、tribute、message、claim、ai、login、register、service、data-request 等；key 多由 `ip`/`user_id` 直接拼接，无统一场景前缀与敏感值过滤。
- **NEEDS_VERIFICATION**：生产限流驱动是 MySQL 还是 Redis（取决于 `RATE_DRIVER`/`REDIS_URL`）；本轮需两种实现都测试。
- **PROPOSED**：新增 `CounterStore::consume(key,max,window)`；MySQL 用唯一键原子 UPSERT，Redis 用 Lua；统一场景化 key（哈希 IP/Token，不存明文）；高风险入口 fail-closed；100 并发测试。

## 5. AI 工作流执行链路（C-01~C-05）

- **CONFIRMED**：`bin/ai-worker.php` 是旧链路执行器：`AiJob::queued()` + `AiStudioService::process()`，CLI only，支持 `--limit`。**它不处理 2.0 `AiWorkflowService` 任务。**
- **CONFIRMED**：`bin/ai-workflow-worker.php` 已存在（约 1378 字节）：CLI only、`--limit`、worker id 由 hostname 派生，流程为 `recoverExpired()` → `claim()` → 逐条 `process($id, $worker)`。缺少 heartbeat、`--max-runtime`、SIGTERM/SIGINT 优雅停止、结构化日志、异常分类。
- **CONFIRMED**：`deploy/` 只有 `nginx.conf` 与 `php.ini`，无 `ai-workflow-worker.service`。
- **CONFIRMED**：`AiWorkflowService` 现有方法：`create/find/forFamily/steps/claim/recoverExpired/process/cancel/addFactCandidate/facts/reviewFact/createOralSession/setConsent/sessions/session/addSegment/reviewSegment/segments/generationLogs`，私有 `assertInputAuthorized/run/localDraft/extractCandidates`。
- **CONFIRMED**：`claim()` 先 SELECT 候选再逐条条件 UPDATE（`:118` 写 `lease_until = time()+900`），无 `lock_version`、fencing token、`FOR UPDATE SKIP LOCKED`；`recoverExpired()` 先 SELECT `processing AND lease_until < NOW()` 再逐条 UPDATE（`:137-145`），无 heartbeat；`process()` 无 lockVersion 参数，完成/失败更新只按 id + status 条件 → 旧 Worker 可覆盖新 Worker 结果。
- **PROPOSED**：新增 `lock_version/heartbeat_at/last_error_at` 迁移（新文件，不改历史迁移）；`heartbeat()`、`process(id, worker, lockVersion)`、`recoverExpired()` 条件 UPDATE + 审计；重写独立 Worker 与 systemd unit；两 Worker 并发、租约超时、取消冲突、旧 Worker 覆盖等测试。

## 6. ProcessingClient 与 Provider 安全（D-01~D-04）

- **CONFIRMED**：`app/Services/Ai/ProcessingClient.php` 现有 `enabled/label/describe/recognize/assertKind`；`recognize()` 直接用后台配置的 `base_url` 拼接 `/ocr` 或 `/transcriptions`，POST JSON（含 `file_base64`），带 Bearer key，仅设 `CURLOPT_TIMEOUT`。
- **CONFIRMED**：无 `validateEndpoint()`；无 HTTPS 强制、userinfo/localhost/私网/链路本地/元数据/IPv6 ULA 校验；无 DNS 解析校验与 rebinding 防护；未禁用重定向；无上传大小/响应体大小限制；`file_get_contents` 整文件读入内存；响应解析 `text/result/data.text/choices[0].message.content`。
- **PROPOSED**：`validateEndpoint()` + DNS pinning；协议适配（custom_json / openai_compatible / provider_specific）；真实 MIME/扩展名/大小/目录校验；错误分类与有限重试；日志脱敏；SSRF/超大文件/无效 JSON 测试。

## 7. 口述历史与 AI 闭环（E/F）

- **CONFIRMED**：`oral_history_sessions` 字段含 family_id、title、topic、interviewer、interviewee_person_id、consent_status（none/granted/revoked）、consent_at/by、audio_file_id、processing_status（draft/queued/processing/completed/failed）、created_by；FK 指向 family/person/user。
- **CONFIRMED**：`oral_history_segments` 字段含 session_id、speaker、start_time、end_time、transcript、corrected_transcript、review_status（pending/confirmed/rejected）、reviewed_by；FK 指向 session/reviewer。
- **CONFIRMED**：两表见 `database/schema.sql` 约 802-840 行，来源迁移 `20261127_020_create_oral_history.php`。
- **CONFIRMED**：`run()` 中可确认的音频路径为 `input.file_id`；`session_id` 输入尚未形成完整的会话音频 → 分段 → 转写 → 审核闭环。
- **CONFIRMED（缺口）**：`FamilyV2Controller::workflow()` 直接调用 `AiWorkflowService::find($id)`，无条件返回完整 `$workflow` 与 `steps()`，未做 family 级 Policy 校验，也未做输出最小化（input_json/存储路径等可能泄露）。
- **NEEDS_VERIFICATION**：`addFactCandidate`/`reviewFact` 是否已强制 person 与 family 一致、source 归属与状态流转审计（代码中存在 Policy 调用，但本轮需逐路径测试）。
- **PROPOSED**：`getVisible()` + 授权 steps；事实创建/审核的父子家族与状态校验；口述历史授权撤销后任务取消/清理；session/segment API；workflow 输出白名单。

## 8. API 路由与 Web/小程序对照（G/H）

- **CONFIRMED**：`app/routes-api.php` 共 42 条唯一路径（21 GET / 27 POST，部分同路径不同方法）。旧链路：auth/token、auth/wechat、auth/logout、me、media/{id}、memorials 系列（含 claim/tribute/message/messages/article）、reports、manage/memorials 系列。
- **CONFIRMED**：2.0 链路：families、families/{id}、families/{id}/(members|invitations|persons|genealogy|relationships|relationships/{relationId}/review|archives|events|exhibitions|ai/sessions|ai/workflows)、invitations/accept、persons/{id}、archives/{id}、ai/workflows/{id}(/cancel)、ai/facts/{id}/review、notifications(/read-all)、notifications/{id}/read、exhibitions/{id}/publish。
- **CONFIRMED**：小程序 `app.json` 12 个页面（旧 8 个 + families/list、families/detail、archives/list、notifications/list），`utils/api.js` 44 个函数；无口述历史、展馆、成员管理、档案编辑/标注等页面或接口。
- **CONFIRMED**：`public/assets/js/photo-tags.js` 使用 mousedown/mousemove/mouseup，无 Pointer Events；坐标依赖 offsetLeft/offsetTop/clientWidth。
- **PROPOSED**：G-02 补齐管理接口、G-03 统一 `items + pagination(page,per_page,total,total_pages)`、G-04 接口自动化测试；H-01 Pointer Events 与响应式坐标、H-02 标注管理、H-03 同家族强校验。

## 9. 机构边界（I）

- **CONFIRMED**：`FamilyService.php` 与 `Policy.php` 中无 institution 相关引用；`InstitutionService` 仅平台管理员可调用；`family_institutions` 只是显式关联表。
- **CONFIRMED**：`institution_members` 的存在不代表已实现租户隔离；机构管理员登录、跨家族搜索、机构下载/导出、机构配额均不存在（也未开放）。
- **PROPOSED**：本轮仅做事务一致性、防重复归属、最后 owner 禁止降级、停用行为、审计；不开放上述完整租户功能。

## 10. 迁移与数据库

- **CONFIRMED**：共 26 个迁移文件 `20261127_001` … `20261127_026`；其中 019 memory_facts、020 oral_history、021 ai_generation_logs、022 family_events、023 exhibitions、024 notifications、025 archive_photo_tags、026 institutions。
- **CONFIRMED**：`bin/migrate-v2.php` 维护 `schema_migrations`（version/name/checksum/executed_at/status/error），checksum 变化时拒绝；MySQL DDL 隐式提交，执行器不具备完整 DDL 事务回滚能力。
- **CONFIRMED（纪律）**：本轮不允许修改任何已应用迁移或 checksum；新增结构必须新建迁移文件并同步 `schema.sql`。

## 11. 任务书 vs 代码差异与需调整项

| 任务项 | 任务书假设 | 代码实际 | 处理 |
|---|---|---|---|
| A-01 | 需“新增 token 中间件” | 已有 `api-auth` 但含 Session 回退 | 改造原中间件语义，保留名称；无需新路由 |
| C-05 | Worker“不存在” | 已存在但功能不完整（无 heartbeat/max-runtime/信号） | 重写而非全新创建；新增 systemd unit |
| C-01 | claim 无锁 | 有条件 UPDATE，但无 fencing token/租约版本 | 新增字段迁移 + 严格校验 |
| D-01 | validateEndpoint 不存在 | 确实不存在 | 新建 |
| E-01 | 表可能不足 | 两表已存在且字段基本够用 | 优先复用，必要时新增独立小迁移 |
| F-01 | workflow 详情未授权 | 确认未授权且输出未最小化 | 修 Service + Controller |
| I | 机构表=租户隔离 | 仅为预留关联，无隔离逻辑 | 明确边界，不承诺租户安全 |
| 测试基线 | 109 项 | 确认 109/0，但无并发/SSRF/fencing 测试 | 新增专项与 CI 矩阵 |

## 12. 结论与阶段门禁

- Phase 0 **完成**：未修改业务代码，未改迁移，未推送；仅生成本报告。
- 下一步门禁：需人工审查本报告并明确批准后，才进入 **Phase 1（A-01/A-02/B-01~B-04）**。
- 未决项：生产限流驱动类型、Provider 协议类型、口述历史音频文件来源细节、CI 目标组合的实际可用性，均在对应阶段验证后更新报告。
