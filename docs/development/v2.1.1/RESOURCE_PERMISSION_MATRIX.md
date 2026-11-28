# v2.1.1 — 资源权限矩阵（基于实际代码）

基线：`e723d07`。本矩阵按 `app/Core/Policy.php` 的实际角色/能力映射填写，不采用任务书示例假设。
标注：**C** = CONFIRMED 代码证据；**I** = INFERENCE 待运行时验证。

## 1. 角色与能力来源

- 纪念馆角色（`Policy.php:11-104`）：平台管理员 `admin`；纪念馆成员角色 `owner/editor/viewer`（以 `memorial_members.role` + `status='active'` 为准，`memberRole()` L11-25）；非成员按 `visibility` 与 `verification_status` 判断公开访问。
- 家族角色（`Policy.php:108-231`）：`owner/admin/genealogy_editor/archive_editor/member/guest`；24 项能力默认拒绝；`family.view` 对 public 家族开放，`unlisted` 视为需鉴权；平台管理员**不自动获得家族角色**（L168、L188）。
- 资源可见性（`Policy::canViewResource()` L236-292）：按资源类型白名单 + `status`/`deleted_at` + 家族隐私 + 档案隐私 + 人物隐私/核验状态判断。

## 2. 权限矩阵

| 资源 | Owner | 查看 | 编辑 | 删除 | 分享 | 管理 |
|---|---|---|---|---|---|---|
| 家族 | 创建者/owner（C） | 角色成员或 `privacy=public`（C）；unlisted 需鉴权（C） | `family.manage` → owner/admin（C） | 限定角色 + 关联检查（C，见 `FamilyService`） | 邀请限 owner/admin（`family.members.manage`，C） | owner/admin（C） |
| 家庭成员 | 家族 owner（C） | 角色成员（C） | `family.members.manage`（C） | 同能力 + 角色边界（C） | 不适用 | owner/admin（C） |
| 人物 | 家族（C） | `canViewResource('person')`：disputed→owner/admin，在世/private→owner/admin/genealogy_editor，family→任意角色，public→已核验（C） | `person.edit` / `person.merge`（C） | 限定角色（C） | 不适用 | genealogy_editor 及以上（C） |
| 人物关系/族谱 | 家族（C） | `relationship.submit/review` 与 tree 过滤（C） | `relationship.submit/review`（C） | `revoke()` 校验角色（C） | 不适用 | genealogy_editor/admin/owner（C） |
| 纪念馆 | `memorial_members.owner`（C） | active+verified 且 `public/unlisted`，或成员（C） | `canEdit` = admin/owner/editor（C） | `canManage` = admin/owner（C） | 邀请/分享受角色约束（C） | owner/admin（C） |
| 档案 | 家族/创建者（C） | `archive.view` + 资源可见性（C） | `archive.edit/tag`（C） | `archive.delete.request/approve`（C） | `data.export` 受权限（C） | archive_editor/admin/owner（C） |
| 照片/视频/音频/媒体 | 所属纪念馆（C） | `canViewMedia`：public/visitors 或 members+角色（C） | `canEdit` 馆主/编辑（C） | 馆主/管理员（C） | 签名 URL 绑定当前可见性（C） | manage 路由 `canEdit`（C） |
| 时间线 | 家族（C） | 家族可见性（C） | `timeline.edit`（C） | 同能力（C） | 不适用 | genealogy_editor/admin/owner（C） |
| AI 工作流 | 创建者/家族（C） | Web 有 `family.view` 等（C）；**API 工作流详情有服务层校验（C）** | 创建需 `ai.workflow.create`（C） | 取消需角色校验（C） | 默认禁止分享（C） | owner/admin（C） |
| AI 事实/生成内容 | 家族（C） | `facts()`/`reviewFact()` 有角色校验但**无 consent 复核（C）** | `ai.result.review` owner/admin（C） | 不适用 | 禁止（C） | owner/admin（C） |
| 口述会话/片段 | 家族（C） | Web 有 `family.view` 与 consent（C）；**API `sessions/session/segments` 裸读，无鉴权（C，P0）** | `oral_history.consent.manage` owner/admin（C） | 不适用 | 导出接口授权不足（I） | owner/admin（C） |
| 订单 | 用户/管理员（C） | 仅管理员 Web（C） | 禁止任意修改（C） | 业务规则 + admin（C） | 禁止（C） | 平台管理员（C） |
| 邀请 | 创建者（C） | Web 展示对任意登录者可见（C） | 撤销/角色变更限 owner/admin（C） | 同能力（C） | 链接即凭证 + 邮箱绑定（C） | owner/admin（C） |
| 审核记录 | 平台管理员/馆主（C） | admin 或 `canModerate`（C） | 状态变更限审核角色（C） | 不适用 | 禁止（C） | admin/owner/editor（C） |
| 数据导出/删除请求 | 本人/授权管理员（C） | 仅本人或 admin（C） | 创建需 owner 校验（C） | admin 处理（C） | 短期有效（I） | 平台管理员（C） |

## 3. 必测越权场景与当前覆盖

| 场景 | 覆盖状态 | 证据 / 缺口 |
|---|---|---|
| 用户 A 访问用户 B 家族/纪念馆/人物/档案 | 已覆盖（C） | `tests/run.php` L2235-2251、L2435-2446、L2491-2537、L2852-2873 |
| 成员退出/角色变更后即时失效 | 已覆盖（C） | L2253-2266、L1348-1457 + `Auth::clear`/`Policy::clearCache` 调用点 |
| 分享/邀请撤销、重放、过期 | 部分覆盖（C） | 家族邀请 L2211-2233；纪念馆邀请 legacy 明文回退未测 |
| API 与 Web 权限一致 | **不通过（C）** | 口述会话 API 裸读，Web 有鉴权（见 `03_SECURITY_AND_PERMISSION_AUDIT.md` §3.1） |
| 小程序接口绕过 Web 权限 | **不通过（C）** | 小程序调用同一 API，因此继承上述越权 |
| 管理员权限边界 | 已覆盖（C） | L403-408、L1707-1729 |
| 禁用用户角色缓存失效 | 未覆盖（I） | `Admin/UserController::updateStatus()` 未清理 `Policy::$roles` |
| API 档案详情/创建/标签/导出/删除请求跨家族负例 | 未覆盖 | 仅 happy path |
| API `reviewRelationship/createPerson/cancelWorkflow/exhibitions/events/publishExhibition` 异家族 ID | 未覆盖 | 仅 happy path |

## 4. 结论与 Phase 5 行动

1. **P0**：修复口述会话/片段 API 三个裸读端点，统一调用 `Policy::can()` 并校验 family/user/consent；补跨家族与跨用户负例测试。
2. **P1**：口述会话导出增加成员/consent 校验；`Admin/UserController::updateStatus()` 同时 `Policy::clearCache()`。
3. **P1**：补全 API 负例测试矩阵；评估纪念馆邀请 legacy 明文 token 回退的退役条件。
4. 本矩阵为 Phase 5 的验收基线；修复后需逐项回填测试结果。
