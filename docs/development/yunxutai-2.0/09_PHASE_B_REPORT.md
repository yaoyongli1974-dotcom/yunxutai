# 云叙台 2.0 · 阶段 B 交付报告（基础数据模型与权限）

> 状态：**已实现，待用户验收**。未推送 main，未触碰生产数据库，未修改认证流程，未删除旧功能/旧测试。
> 基线：提交 `ebe8e03`（阶段 A 文档，本地未推），origin/main 仍为 `4e73652`。

## 1. 交付范围

阶段 B 目标：建立可版本化、幂等的增量迁移机制；落地家族基础表；扩展统一 Policy；提供 Web/API 共用的家族与人物基础服务；补齐家族隔离测试与审计入口。

## 2. 文件变更清单

### 新增文件
- `bin/migrate-v2.php`：2.0 迁移执行器。按版本号顺序执行 `database/migrations/*.php`；维护 `schema_migrations`（version/name/checksum/executed_at/status/error）；`--status` 只读查询；checksum 不一致立即中止；failed 版本允许原样重跑。
- `database/migrations/20261127_001_create_schema_migrations.php`
- `database/migrations/20261127_002_create_families.php`
- `database/migrations/20261127_003_create_family_members.php`
- `database/migrations/20261127_004_create_family_invitations.php`
- `database/migrations/20261127_005_create_family_persons.php`
- `database/migrations/20261127_006_create_person_memorial_links.php`
- `app/Services/FamilyService.php`：家族创建/编辑/状态、邀请（令牌仅存 sha256，7 天有效）、接受/撤销/移除/退出、角色调整、成员列表、用户家族列表；关键操作全部审计。
- `app/Services/FamilyPersonService.php`：人物创建/可见性查询/编辑/纪念馆关联；活人默认私密且公开创建或改公开一律拒绝。
- `docs/development/yunxutai-2.0/09_PHASE_B_REPORT.md`（本文件）。

### 修改文件
- `app/Core/Policy.php`：新增 `FAMILY_ROLES`、`FAMILY_CAPABILITIES`（20 项能力）、`familyRole()`、`can()`、`require()`、`canViewResource()`；`clearCache()` 同时覆盖纪念馆与新家族角色缓存。平台 admin 不自动获得家族权限。
- `app/Core/Audit.php`：`log()` 增加可选 `$actorId` 参数（兼容既有调用），使 API/服务层可显式记录操作者。
- `database/schema.sql`：追加与 6 个迁移完全一致的建表语句（由迁移文件生成，禁止单边修改）。
- `bin/test.sh`：安装后连续执行两次 `bin/migrate-v2.php`，验证首次执行与重复执行幂等。
- `tests/run.php`：新增 7 项阶段 B 测试（见第 4 节）。

### 删除文件
无。

## 3. 数据库变化（仅测试库 jidian_test 执行）

新增 6 张表：`schema_migrations`、`families`、`family_members`、`family_invitations`、`family_persons`、`person_memorial_links`。

- 现有 24 张表：未删除、未重命名、未改字段，语义保持不变。
- `person_memorial_links` 通过 FK 关联 `families/family_persons/memorials/users`，旧纪念馆 ID 原样保留。
- `family_members.role` 使用 6 值枚举（owner/admin/genealogy_editor/archive_editor/member/guest）；`status` 为 invited/active/left/banned，退出/封禁后 Policy 立即失效。
- `family_invitations.token_hash` 仅存 SHA-256，明文令牌只在 `invite()` 返回值中出现一次。
- 迁移执行结果：`php bin/migrate-v2.php` 首次 6/6 applied；第二次 6/6 跳过；`--status` 可读出版本、时间与状态。日志在 `storage/logs/test-migrate{,-rerun}.log`（gitignore）。
- **生产库：未执行任何迁移、未做任何写入。**

## 4. 测试结果

- `bash bin/test.sh`：**78 通过 / 0 失败 / 0 跳过**（原 71 项全部保留并通过，新增 7 项）。
- 新增测试：
  1. 迁移版本记录完整且可重复执行（6 个 applied + checksum + 表存在）。
  2. 创建家族时 owner 自动成为 active 成员并写审计。
  3. 邀请令牌：错邮箱/撤销/重放均失效，接受后角色生效；明文不入库。
  4. 跨家族访问一律拒绝且不泄露资源存在性（公开家族访客可读、跨家族管理/人物访问拒绝、require 抛 404）。
  5. 移除/封禁成员的历史权限立即失效；owner 不能直接退出。
  6. 活人资料默认私密，公开创建/更新被拒绝；逝者公开仍须核验后才可见。
  7. 角色调整必须写审计且禁止自审式越权；owner 不可降级。
- `php bin/doctor.php`（jidian_test）：21 通过 / 4 环境警告 / 0 阻断（同阶段 A）。
- 全量 PHP lint（app/bin/tests/migrations）：0 错误。

## 5. 设计冲突复查

- Policy 扩展为新增方法，纪念馆 `canView/canEdit/canManage/...` 语义与 `memorial_members` 未改动，旧测试全通过。
- 家族 owner 不继承旧纪念馆权限：新建人物关联旧馆时必须通过现有 `Policy::canEdit($memorial, $user)`，无权限跃迁。
- 新表集中在阶段 B 迁移，`schema.sql` 由迁移生成，避免双边漂移。
- 未新增 Web 路由与 API 路由（按阶段划分属于 C/G），因此本阶段不产生可访问的新入口。

## 6. 尚未解决的问题

1. **生产库核对**：生产库版本、现有结构与 `schema.sql` 是否完全一致仍为 NEEDS_VERIFICATION；上线前必须只读核对、备份、演练后由用户确认执行迁移。
2. **DDL 事务边界**：MySQL DDL 隐式提交，迁移自身靠 `IF NOT EXISTS` 保证可重跑；失败时记录 failed 状态并保留原始错误，不做表级自动回滚。
3. **schema.sql 与迁移一致性自动校验**：当前由生成保证，CI 结构差异比对按计划在阶段 G 实现。
4. **邀请邮件发送**：本阶段只生成令牌与审计，未接入通知中心（阶段 F），邮件投递需人工或后续接入。
5. **平台 admin 审计**：现阶段不赋予平台 admin 隐式家族权限；后续若开放跨站管理，需单独设计审计入口并评审。
6. **延期到后续阶段**：支系/关系/谱系（C）、档案与文件（D）、AI 工作流（E）、时间轴/展馆/通知（F）、Web/API/小程序路由（C~G）。
7. **人物公开审核流程**：本阶段仅保证新建人物不自动成为已核验事实；审核界面与授权记录属于 C/F。
