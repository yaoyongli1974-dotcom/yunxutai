# 云叙台 v2.1.0 — Phase B 安全修复报告

分支：`feature/v2.1.0-production-hardening`  
基线：`49fc14e94adeadcf4476873f8e19413ac16a30f9`（v2.0.1）  
执行日期：2026-11-28  
原则：最小修改、根因修复、不删测试、不降 PHPStan、不放宽扫描、不改业务语义（安全边界除外）。

---

## 1. 本阶段修复清单

| 编号 | 风险 ID | 修复内容 | 主要文件 | 结果 |
|---|---|---|---|---|
| B-1 | R-01 | `APP_KEY` 缺失/占位符在非 `local`/`testing` 环境 fail-closed；`APP_ENV` 未设置按未知环境处理 | `config/config.php`、`.env.example`、`app/bootstrap.php` | PASS |
| B-2 | R-01 / R-19 / N-02 | 签名 URL 绑定媒体可见性并缩短 TTL 至 300s；私有媒体 `no-store`；安全 cookie 仅在可信代理下信任 `X-Forwarded-Proto` | `app/Core/MediaSign.php`、`app/Api/Presenter.php`、`app/Api/Controllers/MediaController.php`、`app/Controllers/MediaController.php`、`app/bootstrap.php` | PASS |
| B-3 | R-02 | 纪念馆邀请新增 `token_hash` 迁移与查找；接受时必须匹配受邀邮箱；新建邀请强制有效邮箱 | `database/migrations/20261128_001_invitation_token_hash.php`、`app/Models/Invitation.php`、`app/Controllers/InvitationController.php`、`app/Controllers/Manage/MemberController.php`、`app/Api/Controllers/MemberController.php` | PASS |
| B-4 | R-12 | 修改密码后批量吊销该用户全部未撤销 API Token | `app/Core/ApiAuth.php`、`app/Controllers/AccountController.php` | PASS |
| B-5 | R-13 | 登录失败/成功每次凭证尝试都计入 IP 维度 30/3600，成功时重置 IP 计数，防止跨账号密码喷洒 | `app/Controllers/AuthController.php` | PASS |
| B-6 | R-03 | AI 任务执行前复核会话同意；撤回同意同时取消 `queued/processing/failed`；租约恢复不再复活已撤回同意的任务 | `app/Services/AiWorkflowService.php` | PASS |
| B-7 | N-01 | 新增 `Auth::clear()`，并在成员角色/移除、用户状态/TOTP 变更后清除静态身份与角色缓存 | `app/Core/Auth.php`、成员与用户管理控制器 | PASS |
| B-8 | N-03 | `RateLimiter::blocked()` 增加与 `hit()` 一致的 fail-closed 异常保护 | `app/Core/RateLimiter.php` | PASS |
| B-9 | N-04 | 2FA 待验证上下文绑定 UA hash 与 IP，跨环境复制会话不能完成验证 | `app/Controllers/AuthController.php` | PASS |
| B-10 | N-07 / R-19 | 签名媒体响应 `Cache-Control: no-store, must-revalidate`，授权下调后旧签名立即 404；同步 TTL 文档 | `app/Api/Controllers/MediaController.php`、`app/Controllers/MediaController.php`、`docs/api.md`、`miniprogram/README.md`、Phase 0 文档 | PASS |

---

## 2. 根因与修复说明

### B-1/B-2/B-10 媒体签名与缓存
- 根因：`config/config.php` 在 `APP_KEY` 缺失时静默使用字面量开发密钥；`MediaSign` 仅对 `(id, exp)` 签名且 TTL 900s；签名端点无身份时只检查 `status='active'`，不复核可见性；私有媒体允许浏览器缓存 300 秒。
- 修复：非 local/testing 禁止占位密钥；签名载荷加入 `visibility`，TTL 300s；有用户身份时仍执行 `Policy::canViewMedia()`；小程序 `<image>` 无法携带 Authorization，签名仍是短时凭证，但可见性下调会使旧签名失效；私有媒体统一 `no-store, must-revalidate`。
- 兼容性：未改变 `/api/v1/media/{id}` 路由与查询参数；旧签名在 TTL 内若可见性未变仍可用，可见性变化后立即失效。

### B-3 邀请邮箱绑定
- 根因：纪念馆邀请是纯 bearer 链接，明文 token 入库且任何登录用户持链接即可接受；家族邀请已校验邮箱相等。
- 修复：新增迁移写 `token_hash`（保留明文列仅用于存量视图渲染链接）；查找优先 hash，legacy 行回退明文；接受时强制邮箱匹配；新建邀请必须提供有效邮箱。
- 回滚：迁移只新增可空列与唯一索引；删除迁移记录并回滚代码即可恢复旧行为（不建议在生产长期保留旧行为）。

### B-4/B-5/B-7/B-8/B-9 会话与身份边界
- 改密撤销 Token、登录 IP 计数前置但成功清零、静态身份缓存失效、限流 fail-closed、2FA UA/IP 绑定均为最小代码修复，无数据结构变更（除 B-3 迁移）。
- 成功登录重置 IP 计数是为了避免正常用户累计触发封禁；失败尝试仍全部计数，密码喷洒仍会被 30/3600 阻断。

### B-6 AI 同意实时生效
- 根因：同意只在任务创建时检查；Worker 明确不做用户级复核；撤销同意漏掉 `failed` 任务，而 `recoverExpired()` 会把 `failed` 重新入队。
- 修复：`process()` 执行前复核 `session_id` 对应的 `consent_status`；撤销同意取消三种状态；租约恢复与失败重排均跳过已撤回同意的任务并置为 `cancelled`，记录 `ai.workflow.consent_revoked` 审计；异常归类 `input_error`，不重试。

---

## 3. 回归验证证据

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' \| xargs -n1 php -l` | rc=0 |
| Composer | `composer validate --strict` | rc=0，`./composer.json is valid`（仅 composer 自身弃用提示） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | rc=0，`[OK] No errors` |
| Secret Scan | `bash bin/secret-scan.sh` | rc=0，未发现疑似凭据 |
| 小程序 JS | `find miniprogram -name '*.js' \| xargs -n1 node --check` | rc=0 |
| 集成测试 | `bash bin/test.sh` | rc=0，**133 通过 / 0 失败**（原 128 + 新增 5） |
| Migration | `DB_DATABASE=jidian_test php bin/migrate-v2.php --status` | rc=0，28 个版本全部 applied，含 `20261128_001`；重复执行由 `bin/test.sh` 覆盖 |
| Doctor | `php bin/doctor.php` | rc=0，0 阻断（4 条环境警告） |
| Diff | `git diff --check` | rc=0 |

详细测试用例见 `11_AUTHORIZATION_TEST_REPORT.md` 与 `12_MEDIA_SECURITY_REPORT.md`。

---

## 4. 风险与回滚

- B-3 新增迁移会影响邀请写入路径：迁移未应用时 `Invitation::create()` 写入 `token_hash` 会失败；部署必须先执行迁移。`bin/test.sh` 已在测试库验证空库安装 + 迁移顺序。
- B-1 会让漏配 `APP_ENV`/`APP_KEY` 的生产部署在配置加载阶段硬失败，这是预期的 fail-closed 行为；恢复环境变量即可，无需数据修复。
- B-2 使小程序签名 URL TTL 从 900s 降为 300s；过期后客户端按现有逻辑重新拉详情即可。
- B-9 会使 2FA 过程中 UA/IP 变化的用户需重新登录，属预期安全行为。
- 其余修复均为代码条件判断变更，可通过回退对应提交恢复，不涉及不可逆数据结构改动。

## 5. 本阶段未处理项

- R-04 归档批准删除后的物理文件清理：涉及数据保留/法律判断，按计划留到 Phase C 并需人工确认删除时机。
- R-05/R-06/R-07/R-08/R-09/R-10/R-11 等 P1 数据库与 AI 并发项为 Phase C。
- R-24/R-25/R-26/R-27/R-28/R-29/R-30 为 Phase D/E。
- N-06 上传绕过、N-09 公开家族工作流可见性、N-10 生产配置核对仍需后续验证或授权。

**结论：Phase B 所有 P0 安全修复已完成，回归全绿，允许进入 Phase C（P1 数据与任务可靠性）。**
