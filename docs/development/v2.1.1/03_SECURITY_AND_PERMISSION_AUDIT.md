# v2.1.1 Phase 0 — 安全与资源级权限审计

基线：`e723d07`。审计方式：只读代码审查 + 现有测试核对。标注：**CONFIRMED**（有代码/行号证据）/ **INFERENCE**（推理，未运行时验证）。

## 1. 权限模型现状（CONFIRMED）

`app/Core/Policy.php` 是唯一授权中心，采用**纪念馆角色**与**家族能力**双模型：

- 纪念馆：`memberRole()`（L11-25，读 `memorial_members.role` 且 `status='active'`，缓存于 `self::$roles`）、`role()`（L27-36，平台管理员恒为 `admin`）、`canView/canEdit/canManage/canModerate/canPost/canViewMedia/isIndexable`（L38-104）。
- 家族：`FAMILY_ROLES = owner/admin/genealogy_editor/archive_editor/member/guest`（L108）；能力→角色映射含 24 项能力（L111-134），`can()` 默认拒绝（L170-175），`family.view` 允许 public 家族（L189-195），`.view` 类能力二次校验资源可见性（L212-221），`require()` 将拒绝映射为 404（L226-231），`canViewResource()` 做资源类型白名单、状态、家族隐私、档案/人物隐私与核验状态判断（L236-292）。平台管理员**不自动获得家族角色**（L168、L188）。
- 存储约束：`family_members.role/status` ENUM（`20261127_003:12-13`）、`families.privacy` ENUM(private/unlisted/public) 默认 private（`002:16,18`）、`memorials.visibility`（`schema.sql:85-97`）、`media.visibility`（`schema.sql:214-215`）、`archives.status`（`011:19`）。
- 邀请/令牌：家族邀请仅存 `token_hash`（`004:12-15`）；纪念馆邀请经 `20261128_001` 增加 `token_hash`，`Invitation::findOpenByToken()`（`:27-42`）校验未接受/未撤销/未过期，保留 legacy 明文回退（L40-41）；API Token 仅存 sha256、30 天 TTL、校验撤销/过期/用户状态（`ApiAuth.php:14,21-35,49-59`）。
- 缓存失效：`Policy::clearCache()` 已在主要家族/成员/邀请/角色变更点调用；`Auth::clear()` 在成员角色与用户状态变更点调用。

## 2. 各资源授权落点（CONFIRMED）

| 资源 | Web | API / 服务 | 小程序 |
|---|---|---|---|
| 家族 | `FamilyController::family()` `Policy::can()`（`:67-75`） | `FamilyV2Controller::family()` `canViewResource`（`:84-97`）；`FamilyService` 各变更 `Policy::require` | 同一 `/api/v1` + Bearer |
| 成员 | `Manage/MemberController`（`:190-195` `canManage`） | `Api/MemberController`（`:242`）；`FamilyService`（`:269-311`） | 可达 |
| 人物 | `FamilyPersonController`（`:24,41-42`） | `FamilyV2Controller`（`:132-178`）、`FamilyPersonService` | 可达 |
| 关系/族谱 | `GenealogyController`（`:22,41-42`） | `FamilyV2Controller`（`:180-213`）、`GenealogyService` | 可达 |
| 纪念馆 | `MemorialController`（`:30,56-60,249`）、Manage `canEdit/canManage` | `BaseController::loadViewable/loadPostable`（`:36-61`） | 可达 |
| 档案 | `ArchiveController::family()/archive()`（`:23-40`） | `FamilyV2Controller`（`:217-247`）、`ArchiveService` 各能力 | 列表/详情可达，下载/审批 Web-only |
| 媒体 | `MediaController`（`:17-27`） | 签名端点 `Api/MediaController`（`:32-49`）按当前可见性验签并在有身份时复核 `canViewMedia` | 签名 URL |
| AI 工作流/事实/会话 | `AiWorkshopController`（`:21-28,31-193`） | `FamilyV2Controller`（`:251-449`）、`AiWorkflowService` | 可达（见 §3 越权） |
| 订单 | 仅管理员 Web（`routes.php:260-264`） | `Admin/AiOrderController`（`:50-140`） | 不可达 |
| 邀请 | `InvitationController::show/accept`（`:24-104`，accept 校验邮箱 L88-91） | `FamilyV2Controller::acceptInvitation`（`:117-128`）、`FamilyService`（`:175-230`） | 可达 |
| 通知 | `routes.php:202-205` | `FamilyV2Controller`（`:359-378`），`WHERE user_id=?`（`NotificationService:74-101`） | 可达 |
| 审核 | `Manage/ModerationController`（`canModerate`）、admin 中间件 | 无 `api-admin` 路由 | 不可达 |
| 数据请求/导出 | `AccountController::requestData`（`:191-233`，owner 校验 L207-215） | admin Web（`:249-250`）、`ArchiveService::exportBundle` | 不可达 |

## 3. 已确认越权与一致性缺口

### 3.1 R-P0-01 口述会话/片段 API 跨家族越权读（CONFIRMED，最高优先级）

- 路由 `routes-api.php:83,85,88`（均仅 `api-auth`）→ `FamilyV2Controller::oralSessions()`（`:305-308`）、`oralSession()`（`:390-397`）、`sessionSegments()`（`:410-413`）。
- 底层 `AiWorkflowService::sessions(int)`（`:573-580`）、`session(int)`（`:582-585`）、`segments(int)`（`:630-633`）为**裸 `Db::fetch/fetchAll`，无 Policy / user / family 校验**。
- 任何持有效 Bearer Token 的用户可读取任意 `session_id` 的标题/主题/授权状态/音频文件 ID，或任意会话的转写片段，并可按 `family_id` 枚举会话。
- Web 端同流程有鉴权（`AiWorkshopController.php:34,66-70`），形成 **Web 与 API 权限不一致**。
- **INFERENCE**：可导致在世人物口述历史隐私泄露。Phase 5 必须修复并补跨家族/跨用户越权测试。

### 3.2 R-P0-02 口述会话导出授权不足（INFERENCE）

`FamilyV2Controller::exportSession()`（`:447-450`）→ `exportSessionTranscript()`（`:928-946`）仅用 `familyVisible()`（`:881-889`）；当家族 `privacy='public'` 时，**任何已认证用户**均可导出，未校验成员身份或 consent。Web 无对应导出端点。

### 3.3 R-P1-03 禁用用户角色缓存滞留（INFERENCE）

`Admin/UserController::updateStatus()`（`:59-61`）仅调用 `Auth::clear()`，未调用 `Policy::clearCache()`；`Policy::$roles` 无 TTL，`familyRole()/memberRole()` 不检查 `users.status`，因此长驻进程内被禁用用户的缓存角色可能继续生效。其他角色/状态/所有权变更点均已清理。

### 3.4 其他低风险（CONFIRMED / INFERENCE）

- `Invitation::findOpenByToken()` 保留 legacy 明文 token 回退（CONFIRMED）；accept 时邮箱绑定已生效，展示页 `show()` 仍对任意登录者呈现邀请内容（仅展示，CONFIRMED）。
- `ArchiveController::decideDelete()`（`:264-270`）加载档案时未做可见性校验，但 `approveDelete()` 内重新授权；跳转使用客户端提供的 `family_id`（INFERENCE）。
- API 无 `api-admin` 路由，审核/数据请求/订单/家族退出/档案上传下载导出等仅 Web 可达；小程序无法覆盖这些流程（CONFIRMED）。

## 4. 现有越权测试覆盖与缺口

已覆盖：policy 单元（L285-304）、未授权 admin（L403-408）、editor 403（L409-422）、未核验公开纪念馆 404（L476）、媒体访客 404（L1013/1033/1058）、API 401 信封（L1191-1208）、签名媒体篡改/过期（L1269-1283）、claim 越权（L1286-1314）、成员管理 403/404（L1348-1457）、家族 token 无邮箱/撤销/重放（L2211-2233）、跨家族拒绝（L2235-2251）、移除/封禁成员即时失效（L2253-2266）、在世人物隐私（L2268-2293）、档案跨家族下载 404（L2491-2537）、照片标签越权（L2932-2960）、API token 伪造/撤销/过期（L2890-2911、L3006-3019）等。

缺口（需新增）：`/api/v1/ai/sessions/{id}`、`/segments`、`/families/{id}/ai/sessions` 的跨家族/跨用户调用；API 档案详情/创建/标签/导出/删除请求的跨家族负例；API `reviewRelationship/createPerson/cancelWorkflow/exhibitions/events/publishExhibition` 的异家族 ID 负例；unlisted 家族“持链接访问”语义；admin 无家族角色的断言；禁用用户后 `Policy::$roles` 失效断言。

## 5. 结论

权限体系**基础实现完整**，但存在一处**已确认的 API 口述会话越权读 P0** 与一处导出授权不足，必须在 Phase 5 修复后方可建议发布 v2.1.1。权限矩阵见 `RESOURCE_PERMISSION_MATRIX.md`；本阶段未修改任何代码。
