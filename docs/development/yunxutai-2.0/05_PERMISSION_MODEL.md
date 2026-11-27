# 云叙台 2.0 · 统一权限模型（阶段 A）

> 现状为 **CONFIRMED**，2.0 设计为 **PROPOSED**。扩展 `app/Core/Policy.php`，不新增平行权限实现。

## 1. 设计原则

1. **默认拒绝**：未显式授权即不可访问；资源不存在与无权访问返回相同外部表现（404/403 且不泄露存在性）。
2. **后端授权优先**：Web 与 `/api/v1` 共用 Policy；前端仅根据能力隐藏入口，不作为安全边界。
3. **双层校验**：先校验用户是否属于资源所属家族且状态 active，再校验角色能力，最后校验资源自身 privacy / verification / status。
4. **family_id 隔离**：所有家族查询带 family_id；跨家族访问一律拒绝。
5. **活人默认私密**：`family_persons.is_deceased=0` 或 `privacy=private` 的资源须显式授权 + 审核；退出/封禁成员的历史链接与令牌立即失效。
6. **高风险审计**：成员角色调整、关系确认、档案删除/下载、AI 事实确认、展馆发布、数据导出必须记录 `audit_logs`。

## 2. 角色（PROPOSED）

沿用纪念馆角色语义，新增家族层级：

| 角色 | 说明 | 与纪念馆角色对应 |
|---|---|---|
| owner | 家族创建者，全部能力，不可被普通管理员移除 | owner |
| admin | 家族管理员，除转让/解散外的管理能力 | admin |
| genealogy_editor | 人物、关系、支系、谱系编辑；关系确认需 admin 复核 | editor |
| archive_editor | 档案上传、编辑、标签、版本；删除需申请 | editor |
| member | 普通成员，查看授权内容、提交候选资料/关系 | 受邀成员 |
| guest | 受限访问，仅被显式授权的私密资源 | 无对应 |

平台 `admin` 仍具备跨站管理能力，但操作必须审计；平台 admin 不自动成为家族成员。

## 3. 能力清单（PROPOSED）

在 `Policy` 中新增能力常量与方法（命名建议）：

| 能力 | 允许角色 | 说明 |
|---|---|---|
| `family.view` | 全部 active 成员（公开家族额外允许访客） | 家族主页与统计 |
| `family.manage` | owner, admin | 资料、隐私、状态 |
| `family.members.manage` | owner, admin | 邀请/角色/移除/退出 |
| `person.view` | active 成员 + 经授权的访客 | 私密人物需额外授权 |
| `person.edit` | owner, admin, genealogy_editor | 人物资料、照片、馆关联 |
| `person.merge` | owner, admin | 高风险，需二次确认与审计 |
| `relationship.submit` | 全部 active 成员 | 仅生成 pending 候选 |
| `relationship.review` | owner, admin | 确认/争议/撤销，禁止自审本人提交（建议） |
| `archive.view` | active 成员 + 经授权的访客 | 私密档案需额外授权 |
| `archive.upload` | owner, admin, archive_editor | 文件安全校验在服务层 |
| `archive.edit` | owner, admin, archive_editor | 标签、版本、关联 |
| `archive.delete.request` | 全部 active 成员 | 进入审批，不直接删除 |
| `archive.delete.approve` | owner, admin | 高风险审计 |
| `file.download` | 通过资源授权者 | 敏感材料需原因 + 审计 |
| `ai.workflow.create` | owner, admin, genealogy_editor, archive_editor | 需先通过资料授权检查 |
| `ai.result.review` | owner, admin | AI 事实确认/争议/拒绝 |
| `oral_history.consent.manage` | owner, admin + 受访者授权 | 授权与撤回 |
| `exhibition.edit` | owner, admin, archive_editor | 草稿与内容编排 |
| `exhibition.publish` | owner, admin | 校验引用内容可见性 |
| `data.export` | owner, admin | 审计 + 记录导出范围 |

## 4. Policy 接口设计（PROPOSED）

```php
// 统一入口：用户 × 家族 × 资源 × 能力
Policy::familyRole(int $familyId, ?int $userId): ?string;          // 仅 active 成员
Policy::can(string $capability, array $context, ?array $user): bool;
Policy::require(string $capability, array $context, ?array $user): void; // 失败抛 HttpException/ApiException
Policy::canViewResource(string $type, int $id, ?int $userId): bool;      // 资源级 + family_id 隔离
Policy::clearCache(): void;                                              // 成员/角色变更后调用
```

- `context` 至少包含 `family_id`，可选 `resource`（privacy/status/verification_status/owner）。
- 缓存：现有 `Policy::$roles` 是请求内静态缓存；角色/成员状态变更后必须调用 `clearCache()`，并在邀请接受、移除、封禁、角色调整的服务方法中强制调用。
- Web 控制器在调用服务前不重复判定；服务层统一 `require()`；API 用同一方法，异常由 `Api::guard()` 转 JSON。

## 5. 资源级规则（PROPOSED）

| 资源 | 可见条件 |
|---|---|
| 家族主页 | privacy=public，或当前用户 active 成员；unlisted 需知道链接且经授权 |
| 人物 | deceased 且 privacy=family/public 且 verified；活人默认仅 owner/admin/被授权者；disputed 仅成员可见 |
| 档案 | privacy=family/public 且 status=active；private 需资源级授权 |
| 口述历史 | consent_status=granted 且 privacy 允许；withdrawn 后所有访客与 AI 分发立即失效 |
| 关系 | status=active 才进入正式谱系；pending/disputed/revoked 不公开展示 |
| 展馆 | status=published；私密内容项按 visibility_override 二次校验 |
| 纪念馆（旧） | 保留现有 `Policy::canView` / `canViewMedia` 语义，核验门槛不变 |

## 6. 必测越权场景（PROPOSED，加入阶段 B 测试）

1. 家族 A 用户访问家族 B 人物 / 档案 / 事件 / 展馆 → 拒绝。
2. 普通成员执行管理员操作（角色调整、关系确认、展馆发布、AI 事实确认、数据导出）→ 拒绝。
3. 已退出 / 已撤销邀请 / 已封禁成员使用旧链接或旧令牌 → 拒绝。
4. 修改 URL 中的 ID（person/archive/event/exhibition/file）→ 拒绝且不泄露存在性。
5. 小程序 API 越权（跨家族、伪造 user_id、令牌失效）→ 拒绝。
6. 私密档案 / 私密人物 / 未授权口述历史直接访问 → 拒绝。
7. 关系审核权限绕过（提交者自审、前端传 reviewed_by）→ 拒绝。
8. 敏感文件越权下载与无原因下载 → 拒绝并审计。
9. 活人资料默认公开检查：任何新建活人人物若 privacy=public 且无授权/审核 → 创建即拒绝。

## 7. 与现有权限的兼容

- 纪念馆 `owner/editor/admin` 语义不变；`memorial_members` 表不改结构。
- 家族 owner 不自动获得旧纪念馆权限；需通过现有邀请/成员流程取得，避免权限跃迁。
- 现有 `verification_status` 核验门槛继续作为公开与索引前置条件，2.0 人物公开审核复用同一思路但不共用记录。
- 平台 admin 对家族数据的访问必须写审计；不授予“隐式全能”给前端角色字段。
