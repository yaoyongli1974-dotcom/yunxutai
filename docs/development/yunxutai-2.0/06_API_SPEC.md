# 云叙台 2.0 · API 规格（阶段 A）

> 现有 20 条 `/api/v1` 路由为 **CONFIRMED**；以下新增接口均为 **PROPOSED**，实际路径/方法以现有命名规范复核后为准，不照搬不改。

## 1. 通用约定（沿用现状）

- Base：`/api/v1`；认证：`Authorization: Bearer <api_token>`（令牌 TTL 30 天，`api_tokens` 表）。
- 所有端点经 `Api::route()` → `Api::guard()`；成功 `{"data": ...}`，失败 `{"error":{"code","message","fields?"}}`。
- 分页请求 `page`、`perPage`（默认 12，上限 20）；响应 `{items, total, page, perPage}`。
- 参数校验统一 `Validator`；变更操作返回资源完整表示或 `{id, status}`。
- 限流：现有 throttle（MySQL）/可选 Redis；新写操作按用户 + 家族限流。
- 认证边界：浏览器会话存在也不能绕过 Bearer Token / CSRF；API 不读 Web session 作为身份来源（除现有签名媒体端点）。
- 幂等：创建类写操作支持 `Idempotency-Key` 头或请求体 `idempotency_key`，服务端唯一约束防重；并发更新使用 `updated_at` 条件或版本号。
- 错误码：`not_authenticated`(401)、`forbidden`(403，资源存在性不外泄时用 404)、`not_found`(404)、`validation_error`(422)、`conflict`(409)、`rate_limited`(429)、`internal_error`(500)。

## 2. 现有接口（CONFIRMED，禁止破坏）

| 方法 | 路径 | 认证 |
|---|---|---|
| POST | `/auth/token`、`/auth/wechat` | 公开 |
| POST | `/auth/logout`、GET `/me` | api-auth |
| GET | `/media/{id}` | 签名 URL |
| GET | `/memorials`、`/memorials/{slug}`、`/memorials/{slug}/articles/{id}` | 公开 |
| POST | `/memorials/{slug}/tribute`、`/memorials/{slug}/message` | api-auth |
| GET | `/memorials/{slug}/messages` | 公开 |
| POST | `/reports`、`/memorials/{slug}/claim` | api-auth |
| GET/POST | `/manage/memorials` | api-auth |
| GET | `/manage/memorials/{id}/members` | api-auth |
| POST | `/manage/memorials/{id}/members/invite`、`.../{memberId}/role`、`.../{memberId}/remove`、`.../invitations/{invitationId}/revoke` | api-auth |

## 3. 新增：家族与成员（PROPOSED）

| 方法 | 路径 | 能力 | 说明 |
|---|---|---|---|
| GET | `/families` | 当前用户可见家族列表 | 分页，仅 active 成员 + public |
| POST | `/families` | 登录用户 | 创建者自动 owner；创建限制校验 |
| GET | `/families/{id}` | `family.view` | 统计按可见数据计算 |
| PATCH | `/families/{id}` | `family.manage` | 资料/隐私/状态 |
| GET | `/families/{id}/members` | `family.view` | active 成员列表 |
| POST | `/families/{id}/invitations` | `family.members.manage` | 有效期 + 令牌，只返一次性令牌 |
| POST | `/families/{id}/invitations/{invitationId}/accept` | 被邀请者 | 校验邮箱与令牌；写审计 |
| POST | `/families/{id}/invitations/{invitationId}/revoke` | `family.members.manage` | 撤销后令牌失效 |
| GET | `/families/{id}/invitations` | `family.members.manage` | 待接受/已撤销列表 |
| POST | `/families/{id}/members` | 管理员添加 | 角色后端决定 |
| PATCH | `/families/{id}/members/{userId}` | `family.members.manage` | 角色调整 |
| DELETE | `/families/{id}/members/{userId}` | `family.members.manage` 或本人退出 | left/banned 即时失效 |
| GET | `/me/families` | 登录用户 | 多家族上下文与角色 |

## 4. 新增：人物与谱系（PROPOSED）

| 方法 | 路径 | 能力 | 说明 |
|---|---|---|---|
| GET | `/families/{id}/persons` | `person.view` | 分页/搜索/隐私过滤 |
| POST | `/families/{id}/persons` | `person.edit` | 活人默认 private |
| GET | `/persons/{id}` | `person.view` | 含可见档案/关系摘要 |
| PATCH | `/persons/{id}` | `person.edit` | 资料/隐私/馆关联 |
| POST | `/families/{id}/persons/merge` | `person.merge` | 主/从人物、确认后保留审计 |
| GET | `/families/{id}/relationships` | `person.view` | 仅 active 关系公开返回 |
| POST | `/families/{id}/relationships` | `relationship.submit` | 生成 pending |
| POST | `/relationships/{id}/review` | `relationship.review` | confirm/dispute/reject |
| POST | `/relationships/{id}/revoke` | `relationship.review` | 状态撤销，不物理删除 |
| GET | `/families/{id}/genealogy` | `person.view` | **分页/按代际或分支加载**，禁止全量 |
| GET | `/families/{id}/branches` | `family.view` | 支系树 |

## 5. 新增：档案与文件（PROPOSED）

| 方法 | 路径 | 能力 | 说明 |
|---|---|---|---|
| GET | `/families/{id}/archives` | `archive.view` | 分页/类型/标签/日期筛选 |
| POST | `/families/{id}/archives` | `archive.upload` | 可带文件（multipart） |
| GET | `/archives/{id}` | `archive.view` | 含版本、人物、事件 |
| PATCH | `/archives/{id}` | `archive.edit` | 标签/关联/描述 |
| POST | `/archives/{id}/files` | `archive.upload` | MIME/大小/SHA-256 校验 |
| GET | `/archives/{id}/files/{fileId}/download` | `file.download` | 签名 URL，敏感材料需原因 + 审计 |
| GET | `/archives/{id}/versions` | `archive.view` | 版本列表 |
| POST | `/archives/{id}/delete-request` | `archive.delete.request` | 进入审批，不直接删 |
| GET | `/families/{id}/archive-tags` | `family.view` | 标签列表 |
| POST | `/archives/{id}/annotations` | `archive.edit` | 人工照片标注 |

## 6. 新增：AI、口述历史、时间轴、展馆、通知（PROPOSED）

| 方法 | 路径 | 能力 | 说明 |
|---|---|---|---|
| POST | `/families/{id}/ai/workflows` | `ai.workflow.create` | body: type, subject, idempotency_key, 授权范围 |
| GET | `/ai/workflows/{id}` | 创建者/家族管理员 | 步骤、状态、错误、重试次数 |
| POST | `/ai/workflows/{id}/cancel` | 创建者/家族管理员 | queued/processing 可取消 |
| GET | `/families/{id}/ai/workflows` | `ai.workflow.create` | 分页 |
| POST | `/ai/facts/{id}/review` | `ai.result.review` | confirm/dispute/reject，写来源与审计 |
| GET | `/families/{id}/facts` | `person.view` | 仅已确认事实公开 |
| POST | `/families/{id}/oral-history/sessions` | `archive.upload` | consent_status=pending |
| POST | `/oral-history/sessions/{id}/consent` | 受访者/管理员 | grant/withdraw |
| POST | `/oral-history/sessions/{id}/segments/{segId}/review` | `ai.result.review` | 转写人工校正 |
| GET | `/families/{id}/events` | `family.view` | 时间轴分页/筛选 |
| POST | `/families/{id}/events` | `person.edit` | 事件来源记录 |
| GET | `/families/{id}/timeline` | `family.view` | 合并人物/馆/事件时间轴 |
| GET | `/exhibitions` | 公开/家族可见 | 列表 |
| GET | `/exhibitions/{id}` | 可见性校验 | 内容项逐项校验可见性 |
| POST | `/families/{id}/exhibitions` | `exhibition.edit` | 草稿 |
| POST | `/exhibitions/{id}/publish` | `exhibition.publish` | 校验引用内容；未过审拒绝 |
| GET | `/me/notifications` | 登录用户 | 分页、未读筛选 |
| POST | `/me/notifications/read` | 登录用户 | 批量已读 |
| GET/PATCH | `/me/notification-preferences` | 登录用户 | 通知偏好 |

## 7. 安全约束（PROPOSED）

- 所有新接口在服务端从 token 解析 `user_id`，不接受请求体传入身份；`family_id` 从路径解析并与资源归属比对。
- 文件访问不返回真实磁盘路径；签名 URL 短时有效且绑定用户/资源。
- 列表接口不允许通过参数绕过 family_id；筛选参数白名单化。
- 变更操作记录审计；敏感内容下载记录原因。
- 小程序端只调用 API，不直接访问 Web 路由或静态私有文件。

## 8. 兼容与版本

- 不修改现有端点路径与响应结构；新增端点只在 `/api/v1` 追加。
- 若新增字段，仅做向后兼容的附加字段，不改变旧字段含义。
- 小程序在阶段 G 联调；旧版小程序继续可用（新增接口对其不可见不影响旧流程）。
