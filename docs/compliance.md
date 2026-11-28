# 合规对照与运营要求

> 本文用于产品/研发自查，不构成法律意见。上线前请由法务结合《网络祭扫服务规范》（MZ/T 173—2025）、2023 年网信办与民政部关于网络祭扫的通知、以及属地监管要求复核。

## 1. 产品底线

- 不提供封建迷信服务，不售卖虚拟祭品，不设置香火/祭奠排行榜，不以祭奠次数诱导充值。
- 基础纪念功能免费；增值服务限于真实人工交付（照片修复、纪念视频、生平整理、家族档案、机构版）。
- AI 自助工作室内测期不实际扣费；正式收费前必须在服务页与后台明确单价、单位、交付物与退款规则，价格占位不得直接当作对外报价。
- 不收录、不展示活人被祭奠的内容；收到举报立即进入核验与处置流程。
- 逝者信息由近亲属或获授权者创建，创建时须确认声明。

## 2. 功能对照

| 要求 | 实现 | 位置 |
|---|---|---|
| 逝者身份声明与授权 | 创建纪念馆必须勾选四项声明，记录版本与时间；新馆默认私密 | `MemorialService::DECLARATIONS`、`memorials.declaration_*` |
| 死亡证明/关系材料核验 | 公开前提交死亡证明、亲属关系或申领材料，管理员人工审核；材料私有存储 | `Manage/VerificationController`、`Admin/VerificationController`、`memorial_verifications` |
| 账号实名认证 | 部分：公开馆责任人可追溯（账号+材料+审计），未接入身份证/运营商实名服务；上线前需补齐 | 见「已知合规缺口」 |
| 活人被祭奠举报 | 纪念馆/留言/祭奠/媒体/文章均可举报，含「活人在世」原因；自动标记 urgent | `ReportController`、`reports.reason/priority` |
| 举报复核与暂停 | 后台可隐藏目标、暂停纪念馆并记录结论 | `/admin/reports/{id}` |
| 隐私最小化 | 公开页不显示邮箱/IP；IP 仅 HMAC | `Str::ipHash`、视图 |
| 公开/私密控制 | 四种访问策略 + 搜索收录开关 | `memorials.visibility/allow_search_index` |
| 私密媒体鉴权 | 每个媒体请求服务端校验 | `MediaController`、`Policy::canViewMedia` |
| 内容审核 | 留言/祭奠/文章先审后发；媒体可下架 | Manage/Admin 审核队列 |
| 上传安全 | MIME 白名单、大小限制、随机文件名、非 Web 目录 | `Storage` |
| 账号安全 | 强密码、限流、验证码、会话轮换 | `Auth`、`RateLimiter`、`Captcha` |
| 审计 | 敏感操作留痕 | `audit_logs`、`Audit` |
| 数据权利 | 导出/删除/撤回授权申请入口与后台队列 | `/account/data-request`、`/admin/data-requests` |
| 服务期限与停止运营 | 条款中说明；后台不承诺永久存储 | `pages/terms`、`docs/deployment.md` |
| 未成年人保护 | 条款要求监护人同意；不面向未成年人营销 | 条款/隐私页 |
| 投诉渠道 | 全站举报入口 + 客服邮箱 | `layout.php`、`SUPPORT_EMAIL` |

## 3. 必须建立的运营流程

0. **身份核验**：新馆默认私密；核对死亡证明/官方登记、申请人与逝者关系、材料真实性；无法确认的不予公开。通过后记录核验方式、审核人与时间；材料按敏感个人信息管理。
1. **创建审核**：对疑似活人、冒用名人、敏感事件纪念馆进行人工抽查；高风险馆先暂停再核验。
2. **活人举报 SLA**：收到「活人被祭奠」举报后 24 小时内下架/暂停，核验后决定恢复或删除，并留存记录。
3. **申领核验**：核对申请人与逝者关系、授权文件；通过后仅授予协作者权限，馆主转移需额外人工流程。
4. **内容审核**：政治敏感、侮辱诽谤、违法信息、侵权内容按平台规则处理；保留审核记录与申诉入口。
5. **数据请求**：核验身份与权利基础；导出提供机器可读格式；删除需处理备份、第三方与法定留存例外。
6. **停止运营**：提前不少于 30 日公告；提供导出窗口；到期后删除或匿名化，并清理对象存储/备份/第三方数据。

## 4. 文案与界面约束

- 使用「纪念」「缅怀」「生平」「家族记忆」等克制表述；避免「保佑」「灵验」「香火旺盛」等诱导性话术。
- 不展示祭奠数量排名、贡献榜、等级、徽章。
- 付费服务必须明码标价、说明交付内容与退费规则，且不得影响基础纪念功能的可用性。
- 隐私政策需明确：收集范围、使用目的、公开范围、第三方共享、保存期限、用户权利、投诉方式。

## 5. 数据留存建议

| 数据 | 建议期限 | 说明 |
|---|---|---|
| 账号与纪念馆 | 服务存续期间 | 用户可申请删除 |
| 审计日志 | ≥ 6 个月 | 安全与合规追溯 |
| 举报/申领记录 | ≥ 2 年 | 处理留痕 |
| 上传原文件 | 随纪念馆 | 删除时同步清理备份 |
| 访问日志 | 6–12 个月 | 按最小化原则 |

## 6. 第三方与跨境

- 对象存储、短信、邮件、CDN 需签订数据处理协议，明确地域与删除机制。
- 原则上不跨境传输个人信息；如确需，完成安全评估与告知同意。
- 支付服务商需具备资质；平台不保存银行卡信息。

## 7. 已知合规缺口

- **账号实名**：当前注册仅需邮箱与密码，未采集身份证号、未接入运营商/实名认证服务。公开纪念馆虽以材料核验为前置门槛，并可用账号、IP HMAC、审计日志追溯责任人，但不等同于账号实名。正式上线前应接入合规实名服务，或在首次公开建馆时完成实名核验，并在隐私政策中说明。
- **人工审核能力**：核验、举报、申诉均依赖人工值守；上线前需明确排班、SLA、复核人与升级路径。
- **AI 生成内容**：照片修复/短片/文章/档案可能与事实不符；必须在界面标注 AI 辅助、由家属核对，不得作为逝者身份或历史事实的证明。第三方 AI 处理照片与文本需单独告知并允许拒绝。
- **材料真伪**：人工核对存在局限，必要时接入官方证照核验或要求补充关系材料；对高风险纪念馆先暂停后核验。
- **持续合规**：法规与属地要求可能变化，需定期复核隐私政策、留存期限与第三方协议。

## 8. 上线前合规检查
- [ ] 隐私政策、服务条款、举报规则已上线并可追溯版本。
- [ ] 账号实名/短信验证已接入，或已明确首次公开建馆的实名核验流程。
- [ ] 创建声明、活人举报、申领、数据请求流程有负责人和 SLA。
- [ ] 审核队列有值守；敏感词/图片人工抽查机制。
- [ ] 增值服务合同、价格、交付与退费规则明确。
- [ ] 停止运营与数据处置预案完成。
- [ ] 等保/备案/ICP 等属地要求确认（如适用）。

## 9. 数据治理收口（任务书 P1，基于实际代码）

> 标注：**CONFIRMED** = 有代码证据；**OPEN** = 需补代码或人工流程，当前未实现或未量化。本节只描述现状，不构成已完成承诺。
> 证据：`AccountController::requestData`、`DataRequest`、`Admin/DataRequestController::handle`、`ArchiveService::requestDelete/approveDelete`、`bin/archive-cleanup.php`、`docs/development/v2.1.0/04_DATA_RETENTION_POLICY.md`。

### 9.1 数据生命周期分层

| 层次 | 当前实现 | 状态 |
|---|---|---|
| 业务逻辑删除（状态标记） | `archives.status=deleted/delete_requested` + `deleted_at`、`family_persons.deleted_at`、`users.status=disabled`；审批前可回滚 | CONFIRMED |
| 数据库行物理清理 | 仅档案 worker 删除 `archive_files` 行（`archive-cleanup.php:113`）；人物/纪念馆/账号软删除后无统一物理清理任务 | 部分 CONFIRMED，其余 OPEN |
| 媒体文件物理清理（归档异步队列） | `approveDelete(true)` 在事务内把每个 `archive_files.(file_id, storage_key)` 入 `archive_cleanup_queue`；`bin/archive-cleanup.php` 幂等领取（`FOR UPDATE SKIP LOCKED`，`attempts+1`，默认上限 3），核对 `archives.status='deleted'`，删除原文件+处理后文件，共享 `storage_key` 只关单不删，失败回 `pending/failed` 并写审计 | CONFIRMED |
| 缓存/授权即时失效 | 改密 `ApiAuth::revokeAllFor`；成员退出/角色变更 `Policy::clearCache()` | CONFIRMED |
| 授权缓存失效缺口 | `Admin/UserController::updateStatus()` 禁用用户未调用 `Policy::clearCache()`（`Policy::$roles` 滞留） | OPEN（P1） |
| 备份生命周期 | `bin/backup.php --keep=14`（默认保留 14 份每日），`manifest.json` 记录 sha256/bytes，可选 `BACKUP_ENCRYPTION_KEY` AES-256-CBC 加密 | CONFIRMED |
| 审计/法定留存 | `audit_logs` 只追加、不做物理删除；最小化保留期需人工确认 | 机制 CONFIRMED，期限 OPEN |

### 9.2 数据导出范围与保护措施

- **授权**：申请仅 `export/delete/revoke` 三类，带纪念馆 ID 时校验申请人为 `owner`，限流 3 次/天；档案导出经 `getVisible()` + `Policy::require('data.export')`，该能力限 `owner/admin`（`Policy.php:133`）；无登录则 401。CONFIRMED
- **无跨用户数据**：`exportBundle()` 只返回当前用户有权访问的单一档案，数据为元数据与文件清单（文件名、mime、sha256、大小）+ 标签/人物/版本，不含文件二进制。CONFIRMED
- **审计**：`data_request.create`、`admin.data_request.update`、`archive.exported` 均写 `audit_logs`。CONFIRMED
- **回执**：后台 `handle()` 仅登记 `processing/done/rejected` 并发邮件回执，明确「本次操作不会删除任何数据」，实际导出/删除/撤回需身份核验后离线执行。CONFIRMED（流程），自动化执行 OPEN
- **短期有效下载链接 / 随机文件名 / 到期清理**：当前导出为 JSON 响应（`Response::json`），无短链下载、无随机文件名、无统一过期清理器；权限矩阵「短期有效」仅为推断（I）。OPEN
- 导出文件保留期限未定义（`04_DATA_RETENTION_POLICY.md` §1/§4 标 OPEN）。OPEN

### 9.3 用户可发起的数据操作（支持状态）

| 操作 | 实现证据 | 状态 |
|---|---|---|
| 账号删除申请 | `AccountController::requestData(type=delete)` → `data_requests`，后台 `handle` 登记状态 | 入口 CONFIRMED；账号物理删除/注销OPEN |
| 家族退出 | `FamilyService::leave()` / `FamilyController::leave()`，退出后角色授权即时失效 | CONFIRMED；退出后历史数据物理清理 OPEN |
| 人物删除 | `GenealogyService` 置 `deleted_at`、`privacy=private`、`verification_status=none` | 软删除 CONFIRMED；物理清理 OPEN |
| 纪念馆删除 | 仅后台停用/恢复，无物理删除流程 | OPEN |
| 档案删除 | `requestDelete → delete_requested → approveDelete(true) → deleted → 异步队列清理文件/行` | CONFIRMED |
| AI 任务删除 / 同意撤回 | `AiWorkflowService::cancel()`、`revokeSessionConsent()`；`consent_revoked` 拒绝新任务并取消在途任务 | CONFIRMED；已生成内容保留期 OPEN |
| 分享/邀请 token 撤销 | `FamilyService::revokeInvitation()`、`Invitation::revoke()`、`MemberController::revokeInvitation()`，链接立即失效 | CONFIRMED；纪念馆邀请 legacy 明文 token 回退未退役 OPEN |

### 9.4 备份删除处理（有意保留例外）

- 归档清理 worker 同时删除处理后文件与原副本（`original_storage_key` 与 `storage_key`，`archive-cleanup.php:104-111`），并删除对应 `archive_files` 行，避免残留可恢复的隐私数据。CONFIRMED
- 备份副本**不随单次档案删除即时清除**，按 `bin/backup.php --keep=14` 的 14 份每日滚动淘汰策略自然过期。CONFIRMED
- 该差异为**有意保留例外**：备份是灾难恢复快照，删除请求不追溯改写历史备份点。对外不得宣称「删除即全副本彻底清除」；如需在备份中彻底清除个人信息，须等待对应备份自然滚出淘汰，并配合密钥/介质管理。OPEN（彻底清除时限与口径）
- 备份加密已实现（`BACKUP_ENCRYPTION_KEY` / AES-256-CBC，见 `BACKUP_RESTORE_DRILL.md`）；异地同步、密钥托管与 RPO/RTO 分钟级量化仍 OPEN。
