# 云叙台 2.0 阶段 C 交付报告：家族空间、人物与谱系

## 1. 交付范围

本阶段在阶段 B 的家族基础表与 Policy 之上，完成家族空间 Web 流程、人物管理、亲属关系审核、谱系按需加载、支系管理与人物合并。未改动旧纪念馆功能与认证流程；未触碰生产数据库；未推送 main。

## 2. 新增文件

### 数据库迁移（独立脚本，生产未执行）
- `database/migrations/20261127_007_create_person_relationships.php`：关系表（6 种角色关系 + 配偶/兄弟姐妹/其他，含方向、状态、来源、审核字段）。
- `database/migrations/20261127_008_create_family_branches.php`：家族支系（支持上级支系）。
- `database/migrations/20261127_009_create_person_branch_members.php`：人物-支系关联。
- `database/migrations/20261127_010_create_person_merge_log.php`：人物合并追溯日志。

以上建表语句同步追加至 `database/schema.sql`（与迁移文件一致），测试库首次安装后两次运行迁移均 10/10 就绪。

### 服务层
- `app/Services/GenealogyService.php`：关系提交（永为 pending）、审核/争议/撤销、反向关系统一映射、血缘闭环检测、谱系按需加载（按人物/深度/节点上限）、支系创建与分配、人物合并（迁移纪念馆/支系/关系关联，软删除重复人物并写日志）、同名候选识别。

### 控制器
- `app/Controllers/FamilyController.php`：家族列表、创建、资料编辑、成员管理、邀请/撤销/角色调整/移除/退出。
- `app/Controllers/FamilyPersonController.php`：人物列表、创建、详情、编辑、纪念馆关联、合并。
- `app/Controllers/GenealogyController.php`：谱系图、关系提交/审核/撤销、支系创建与分配。
- `app/Controllers/InvitationController.php`：扩展支持家族邀请令牌（sha256 匹配、邮箱本人接受）。

### 视图（均标记 noindex）
- `app/Views/family/`：`index/create/edit/show/members/invitation.php`
- `app/Views/family/persons/`：`index/create/edit/show/merge.php`
- `app/Views/family/genealogy.php`

### 文档
- `docs/development/yunxutai-2.0/10_PHASE_C_REPORT.md`（本文件）

## 3. 修改文件

- `app/routes.php`：新增 25 条家族/人物/谱系路由，全部带 auth 中间件；`/families/new`、`/families/{id}/persons/new`、`/families/{id}/persons/merge` 先于通配路由注册。
- `app/Views/layout.php`、`app/bootstrap.php` 等 SEO/头部文件本阶段无改动。
- `tests/run.php`：迁移数量断言改为「不少于阶段 B 基线且全部 applied」（迁移随阶段增加属预期变化，未删除原有校验）；新增 8 项阶段 C 测试。
- `database/schema.sql`：追加 4 张表的幂等建表语句。

## 4. 数据库变化（仅测试库已执行）

新增 4 张表：`person_relationships`、`family_branches`、`person_branch_members`、`person_merge_log`。`schema_migrations` 现为 10 个版本；生产库仍未执行任何 2.0 迁移，需先备份、只读核对与演练后由用户确认。

## 5. 安全与权限落实

- 所有写操作先经 `Policy::can/require`，未知能力默认拒绝；跨家族访问统一 404，不泄露资源存在性。
- 关系提交后一律 pending；只有 owner/admin 可确认；AI 推测不得直接建立正式关系（AI 接入将在阶段 E 遵守本约束）。
- 自关联、重复关系、血缘闭环均被拒绝；关系可撤销并立即从谱系消失。
- 活人资料不可创建/改为非私密（Web 表单返回 422）；公开逝者仍须 verified 才可展示。
- 人物合并保留被合并人物记录与审计，不删除档案/馆关联；重复分配支系不产生重复行。
- 邀请令牌仅存 sha256、7 天有效、邮箱匹配、撤销/重放失效。

## 6. 测试结果

- `bash bin/test.sh`：**86 通过 / 0 失败 / 0 跳过**（阶段 A 基线 71 项与阶段 B 7 项全部保留通过，新增 8 项阶段 C 测试）。
- 新增测试覆盖：Web 创建家族与成员页、邀请接受、Web 人物创建与活人隐私 422、关系待审核→确认→撤销、自关联/重复/血缘循环/跨家族拒绝、支系分配幂等、人物合并关联迁移与审计、跨家族 Web 404 与谱系审核入口。
- `php bin/doctor.php`：21 通过 / 4 环境警告 / 0 阻断（警告同阶段 A）。
- 全量 PHP lint：0 错误。

## 7. 尚未解决 / 下一阶段依赖

1. 生产库只读核对、备份与迁移演练仍需用户授权；生产环境不执行任何 2.0 DDL。
2. Web 谱系目前为关系列表与按深度裁剪的按需加载视图，尚未实现拖动/缩放图形界面（可在移动端使用列表视图；图谱增强不阻断业务验收）。
3. 邀请邮件尚未接入统一通知服务（阶段 F）；当前将邀请链接展示给创建者。
4. 家族空间 API（`/api/v1`）与小程序页面将在阶段 G 统一补齐，届时 Web/API 共用本阶段服务层。
5. 数字档案馆、AI 工坊、时间轴/展馆/通知中心尚未实现（阶段 D/E/F）。

## 8. 冲突复查

与现有代码无硬性冲突：旧纪念馆路由、认证流程、Policy 旧能力与 API 均保持原样；阶段 C 仅新增路由与服务，复用阶段 B 的家族表与权限入口。
