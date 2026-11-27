# 云叙台 2.0 · 总体架构（阶段 A）

> 现状为 **CONFIRMED**，2.0 设计为 **PROPOSED**。目录名建议需先与现有命名规范核对后落地。

## 1. 总体形态

CONFIRMED：模块化单体（modular monolith）、PHP 服务端渲染、MySQL/MariaDB、无框架、无 Composer 依赖管理。
PROPOSED：保持该形态，不引入微服务与独立队列中间件。新业务以“领域服务 + 薄控制器”方式纳入现有分层：

```
public/index.php
   └─ app/bootstrap.php（配置、DB、会话、安全头、www 301）
        ├─ app/routes.php          Web SSR 路由（middleware: admin/api-auth …）
        └─ app/routes-api.php      /api/v1 JSON 路由（Api::route 包装）
             ├─ app/Controllers/{Admin,Manage,…}   Web 控制器（薄）
             ├─ app/Api/Controllers/*              API 控制器（薄）
             ├─ app/Services/<Domain>/*            领域服务（业务逻辑唯一来源）
             ├─ app/Models/*                       数据访问
             ├─ app/Core/Policy.php                统一授权（Web/API 共用）
             ├─ app/Core/Storage.php → 文件服务抽象（PROPOSED）
             ├─ app/Core/Notify.php  → 通知服务（PROPOSED）
             └─ app/Core/Audit.php（PROPOSED 统一审计入口）
```

## 2. 模块划分（PROPOSED 目录）

优先核对现有 `app/Services/` 与 `app/Services/Ai/` 命名，建议：

| 模块 | 建议位置 | 职责 |
|---|---|---|
| Family | `app/Services/Family/` | 家族、成员、邀请、上下文 |
| Genealogy | `app/Services/Genealogy/` | 人物、关系、支系、谱系、合并 |
| Archive | `app/Services/Archive/` | 档案、标签、版本、关联、文件 |
| FamilyTimeline | `app/Services/FamilyTimeline/` | 事件、时间轴、纪念日 |
| OralHistory | `app/Services/OralHistory/` | 采访会话、分段、授权 |
| AiWorkflow | `app/Services/Ai/`（复用现有目录） | 工作流、事实、Provider 适配 |
| Exhibition | `app/Services/Exhibition/` | 展馆与内容项、发布状态 |
| Notification | `app/Services/Notification/` | 站内通知、偏好、发送日志 |

约束：不为目录结构重复建设已有功能；`AiStudioService` 原入口保留，作为新工作流的兼容层/调用方。

## 3. 请求生命周期与统一业务逻辑

1. 路由解析 → 中间件（`admin` / `api-auth` / CSRF）→ 控制器仅做参数提取与响应渲染。
2. 控制器调用领域服务；**服务层**做校验、Policy 授权、事务、审计。
3. Web 返回 HTML 视图（复用 `app/Views/layout.php` 与 partials）；API 返回 `Api::ok/error` 统一 JSON。
4. 异常边界：Web 由 error-handler 渲染错误页；API 由 `Api::guard()` 转 JSON；未授权资源统一 404，不暴露存在性。
5. 同一操作只允许一份业务实现：Web 与 API 控制器必须调用同一服务方法，不在控制器内重复权限判断。
6. 每次资源访问验证：当前用户 × 资源所属 `family_id` × 资源自身 privacy/status × 成员状态。

## 4. 多租户隔离模型（PROPOSED）

- 所有家族数据顶层带 `family_id`；`family_members` 决定用户与家族关系（状态 active/invited/left/banned）。
- 服务层提供 `requireFamilyContext(int $familyId, ?int $userId, string $capability)`：先查有效成员关系，再查能力，再查资源。
- 资源级授权：私密档案/人物/口述历史需要额外授权，不因“是家族成员”自动可见。
- 管理员跨平台操作走 `admin` 能力并强制审计；不通过前端传入 `user_id` 认定身份。
- 查询约束：所有列表/详情 SQL 必须带 `family_id` 条件；禁止仅靠应用层拼参数。
- 历史纪念馆：无明确家族归属时继续以纪念馆为隔离单元，不擅自合并。

## 5. 数据访问与事务

CONFIRMED：PDO 封装 `App\Core\Db`；现有 `throttle` 表、文件会话、可选 Redis。
PROPOSED：
- 每个写操作使用显式事务；多表关联（档案↔人物↔文件、关系审核、人物合并）必须原子。
- 迁移提供版本号表与幂等执行；schema.sql 作为全新安装基线，迁移仅做增量，二者不得冲突。
- 软删除/归档：家族人物、档案、展馆等使用 `status` + `deleted_at`（或 archive 状态），不做物理删除；敏感材料依法删除走专门流程。

## 6. 文件服务（PROPOSED 扩展 Storage）

- 接口：`put(original, meta): fileId`、`derive(fileId, role)`、`url(fileId, context)`、`read(fileId)`、`delete(fileId)`、`hash(fileId)`。
- 本地驱动优先（复用 `storage/uploads` 与随机文件名、0640 权限）；接口预留国内对象存储驱动。
- 原文件不可被处理副本覆盖；`archive_files.file_role` 区分 original/thumbnail/restored/preview/transcript/converted。
- 上传校验：finfo MIME 白名单、大小、SHA-256、扩展名映射；图片生成缩略图并清理 EXIF；视频封面/音频元信息由可配置工具生成，失败不阻塞原文件归档。
- 访问：`Media`/`ArchiveFile` 经 Policy 授权后发短时签名 URL（沿用小程序签名媒体模式）；禁止业务层拼接公开路径；下载写审计。

## 7. AI 中枢（PROPOSED 扩展现有 Provider 层）

- `AiClientFactory` 继续负责 Provider 选择；新增统一请求/响应结构（模型名、提示词版本、输入引用、token/成本、错误分类）。
- 工作流与步骤持久化（`ai_workflows` / `ai_workflow_steps`）；worker 从队列表原子领取。
- 可靠性：幂等键唯一约束；`UPDATE ... WHERE status='queued' AND leased_until IS NULL` 原子领取；租约过期重置；失败分类（provider/network/validation/content）；重试上限 + 指数退避；取消标记；异常退出后由下次扫描恢复 processing。
- 事实层：`memory_facts` 全部 pending；人工确认/争议/拒绝；每次 AI 输出保存输入引用与审核记录。涉及私密资料先做授权检查，禁止把敏感原文写入调用日志。
- 旧任务兼容：`ai_jobs` / `ai_orders` 保留；新工作流可引用旧 job id，旧 worker 仍可处理旧任务。

## 8. 通知与审计（PROPOSED）

- `Notify` 保留邮件/日志通道；新通知服务写 `notifications` 表（站内）、读取 `notification_preferences`，异步发送，失败重试 + `notification_logs`。
- 通知失败不得回滚业务事务；重要通知（邀请、审核、安全）默认开启，营销/纪念日可关闭。
- 统一 `Audit::record(action, targetType, targetId, meta)` 写 `audit_logs`；高风险操作（角色调整、关系确认、档案删除/下载、展馆发布、AI 事实确认、数据导出）必须审计。

## 9. 前端与视图（PROPOSED）

- 沿用 SSR 布局、语义类、暖色视觉系统；移动端优先；新增导航入口：我的家族、谱系、档案、AI 工坊、纪念空间、时间轴、展馆、通知中心。
- 谱系图按需加载（按家族/代际/分支分页取节点），节点展开/收起、拖动缩放；不一次性拉全量人物。
- 表单支持草稿保存、二次确认、空状态/错误态/权限态；上传显示进度与失败原因。
- 不做游戏化、虚拟祭品商城、排行榜、过度商业化互动。

## 10. API 与小程序（PROPOSED）

- 继续 `/api/v1` + Bearer Token + `Api::route()` 异常边界；统一分页 `page/perPage`（上限 20）、错误格式 `{error:{code,message,fields?}}`。
- 新增资源型路由见 `06_API_SPEC.md`；变更操作带幂等键或并发条件；API 认证不因浏览器会话存在而绕过。
- 小程序新增家族列表/主页、人物详情、谱系、档案、时间轴、AI 任务状态、通知中心；复杂编辑保留 Web。

## 11. 配置、部署与运维（PROPOSED）

- 配置继续 `.env` + `config/config.php`；新服务配置（Provider、文件驱动、通知）集中追加，禁止把测试配置带入生产。
- CI：PHP lint、测试、迁移检查、安全测试、静态分析/风格检查（尚无 `.github/`，需新建）。
- 部署：部署前检查 → 迁移前备份 → 迁移 → 部署 → 健康检查 → 失败回滚 → worker 重启 → 缓存/日志检查。
- 监控：DB/磁盘/存储/Worker 状态/队列积压/失败任务/通知失败/备份状态与恢复演练。

## 12. 架构原则校验清单

- [ ] 新功能均通过服务层，Web/API 无重复业务逻辑。
- [ ] 所有家族查询带 `family_id`。
- [ ] 所有授权走 Policy，前端权限仅用于展示。
- [ ] 原文件与处理副本分离，访问经授权与审计。
- [ ] AI 输出均为草稿，来源可追溯，不外发未授权资料。
- [ ] 数据库变更仅通过独立迁移，历史数据不删不改。
- [ ] 每阶段完整回归测试通过，旧测试保留。
