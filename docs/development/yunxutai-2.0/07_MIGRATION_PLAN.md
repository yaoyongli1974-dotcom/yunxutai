# 云叙台 2.0 · 数据库迁移与历史数据兼容方案（阶段 A）

> 全部为 **PROPOSED**。基本原则：不直接删除、重命名或破坏现有生产数据；迁移独立、幂等、可核对、可回滚。

## 1. 现状约束（CONFIRMED）

- `database/schema.sql` 定义 24 张现有表；`database/migrations/20261027_verification.sql` 是唯一 SQL 迁移文件，其注释明确要求生产使用幂等的 `php bin/migrate.php`。
- `bin/install.php --fresh` 用于全新/测试安装；`bin/backup.php`、`bin/doctor.php` 已存在，可作为迁移前后检查与备份入口。
- 生产库版本与现有表是否与 schema.sql 完全一致：**NEEDS_VERIFICATION**，阶段 B 前需只读核对。
- 无迁移版本表；无自动回滚演练。

## 2. 迁移机制（PROPOSED）

### 2.1 文件规范
```
database/migrations/
  20261127_001_create_schema_migrations.php
  20261127_002_create_families.php
  20261127_003_create_family_members.php
  20261127_004_create_family_invitations.php
  20261127_005_create_family_persons.php
  20261127_006_create_person_relationships.php
  20261127_007_create_branches.php
  20261127_008_create_archive_tables.php
  20261127_009_create_family_events.php
  20261127_010_create_oral_history_tables.php
  20261127_011_create_memory_facts.php
  20261127_012_create_ai_workflow_tables.php
  20261127_013_create_exhibition_tables.php
  20261127_014_create_notification_tables.php
  20261127_015_backfill_legacy_links.php
```
格式以现有 `bin/migrate.php` 为准（若其为单文件迁移，则将上述编号作为其中有序步骤并保留幂等语义）；**必须先核对现有格式再落地**。

### 2.2 版本与幂等
- 新增 `schema_migrations` 表（version、name、checksum、executed_at、status、error）。
- 每个迁移：执行前检查版本记录；执行成功写记录；已执行则跳过；校验内容 checksum，不一致时报错停止。
- 使用 `CREATE TABLE IF NOT EXISTS`、`ADD COLUMN` 前先 information_schema 检查、唯一索引先查重；禁止裸 `DROP`/`RENAME` 现有表。
- 单迁移内多语句使用事务（DDL 在 MySQL 隐式提交，需用“前置检查 + 失败可重跑”策略，不使用依赖 DDL 回滚的设计）。
- 记录每次执行结果到日志与版本表。

### 2.3 部署流程
1. 部署前检查：`php bin/doctor.php`、DB 只读核对、磁盘空间、Worker 状态。
2. **迁移前备份**：`php bin/backup.php`（确认生成备份文件与校验值）。
3. 试运行：在 `jidian_test` 或生产结构副本上完整跑一遍迁移 + 数据核对。
4. 生产执行：逐迁移执行，失败即停止，不跳过错误。
5. 迁移后：结构检查、数据核对报告、健康检查、Worker 重启、缓存清理。
6. 失败回滚：优先“停止后续迁移 + 恢复备份”；只对新表做删除时须人工确认且仅针对本次新增对象，绝不影响旧表。

## 3. 历史数据兼容映射（PROPOSED）

目标：旧纪念馆继续可用；能建立关联的地方建立关联，无法确定的保持独立。

| 现有数据 | 处理规则 |
|---|---|
| `users` | 不改动；家族 owner/admin 引用原用户 ID |
| `deceased` | 不改动；作为 `family_persons` 的候选数据源之一 |
| `memorials` | 保留原 ID、slug、权限、核验状态、索引开关；新增 `person_memorial_links` 关联，不改馆本身语义 |
| `memorial_members` / `invitations` | 不迁移为家族成员；权限不自动跃迁，需走新邀请流程 |
| `media` | 不复制物理文件；可在 `archive_files` 中登记引用（storage_key 指向原路径），避免重复占用 |
| `life_events` / `articles` | 不改动；可作为 `family_events` / 档案的来源引用（source_type=imported, source_id=原 ID） |
| `ai_jobs` / `ai_orders` | 不改动；`ai_workflows.legacy_job_id` 可选引用，旧 worker 继续处理 |
| `verification_status` | 旧馆核验门槛保持；新人物公开需单独授权/审核，不继承旧馆核验结论 |
| 无法确定家族归属的馆 | 保持独立，不创建家族、不合并人物 |

### 3.1 人物建立规则（保守）
仅当满足**明确规则**时才由旧数据生成 `family_persons`：
1. 纪念馆创建者主动在家族内“关联/导入”该馆，且选择与某个 `deceased` 记录对应；
2. 或家属显式新增人物并填写关联馆。
禁止脚本自动按姓名相近批量合并不同用户的纪念馆为同一人物/家族。

### 3.2 数量核对（迁移报告必须包含）
- 迁移前后：users / memorials / deceased / media / ai_jobs / ai_orders / reports 数量一致。
- 新增：families / family_members / family_persons / person_memorial_links 数量与来源说明。
- 校验：旧馆可访问性抽样（公开/私密/未核验各至少 1 座）、媒体签名 URL 可访问、旧 API 端点回归。
- 报告落盘：`storage/backups/migration-report-<timestamp>.md`（不进版本库）。

## 4. 回滚策略（PROPOSED）

- 结构回滚：只删除本次新增表（按依赖逆序），且仅在确认无写入后执行；现有表一律不删。
- 数据回滚：以迁移前备份为唯一恢复来源；恢复演练在测试环境完成。
- 应用回滚：代码回滚到迁移前提交后，新功能入口需可关闭（配置开关或路由守卫），避免访问不存在的新表。
- 迁移失败时：`schema_migrations` 标记 failed，保留错误详情，修复后以同一 version 重跑（幂等）。

## 5. schema.sql 与迁移的一致性

- `schema.sql`：全新安装基线，包含所有新表的最终定义；由迁移结果生成并人工复核，禁止手改造成与迁移不一致。
- 迁移脚本：只对已有库做增量；两者字段/索引/枚举必须一致。
- CI 检查：全新安装（schema.sql）后的结构 vs 空库依次执行全部迁移后的结构，做差异比对（阶段 G 前完成）。

## 6. 数据保护要求

- 迁移前备份加密；备份含 `storage/verifications` 与 `storage/uploads` 清单（不把敏感材料复制到公开目录）。
- 身份材料、口述历史授权记录迁移时保持原权限；不扩大可见范围。
- 迁移日志不记录密码、令牌、证件内容。
- 生产数据库不得由测试脚本直连；测试始终使用 `jidian_test`。
