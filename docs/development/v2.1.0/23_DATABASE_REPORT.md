# 云叙台 v2.1.0 — 数据库与迁移报告（Phase C）

## 1. 新增迁移

| 版本 | 文件 | 内容 |
|---|---|---|
| 20261128_002 | `database/migrations/20261128_002_ai_fact_provenance.php` | `memory_facts` 增加 `source_archive_id/source_version_id/source_excerpt/subject_type/subject_id` + 2 KEY |
| 20261128_003 | `database/migrations/20261128_003_archive_cleanup_queue.php` | 新建 `archive_cleanup_queue`（UNIQUE(archive_id, storage_key)、KEY(status,attempts)、FK→archives） |
| 20261128_004 | `database/migrations/20261128_004_audit_request_id.php` | `audit_logs` 增加 `request_id CHAR(32)` + KEY |

与 Phase B 的 `20261128_001_invitation_token_hash` 连续，共 4 个 20261128 迁移；累计 31 个版本。未修改任何已发布迁移的历史语义。

## 2. 基线漂移处理（R-05）

原计划“将 027 三列回写 schema.sql”在实跑中导致全新安装后 `20261127_027` ALTER 重复列失败（`SQLSTATE[42S21] Duplicate column name 'lock_version'`）。采用等价兼容处理：

1. `database/schema.sql` **不写**由增量迁移添加的列（027 的 AI 锁字段、004 的 request_id、002 的来源字段）；
2. `bin/install.php` 在创建基线表后自动调用 `migrate-v2.php`，使全新安装即具备完整结构；
3. `bin/doctor.php` 检查迁移状态，缺表或未 applied 直接 fail。

这样既保持“schema.sql 与迁移单源一致”，又满足空库安装即可运行 AI 工作流。

## 3. Doctor 与门禁（R-15）

`bin/doctor.php` 必需表从 9 张扩到 21 张，新增：families、family_members、family_invitations、family_persons、person_relationships、archives、archive_files、ai_workflows、ai_workflow_steps、memory_facts、oral_history_sessions、schema_migrations。新增 `schema_migrations.status <> 'applied'` 数量检查（>0 fail）。警告仍不阻断，必需的 DB 结构问题 fail。

## 4. 验证证据

```bash
bash bin/test.sh                                  # rc=0，138 通过 / 0 失败
DB_DATABASE=jidian_test php bin/migrate-v2.php --status   # rc=0，31 版本全部 applied
DB_DATABASE=jidian_test php bin/migrate-v2.php           # rc=0，重复执行跳过
DB_DATABASE=jidian_test APP_ENV=testing php bin/doctor.php # rc=0，0 阻断 / 5 警告
php -l ...                                        # rc=0
vendor/bin/phpstan analyse --no-progress          # [OK] No errors rc=0
```

本地数据库：`11.8.9-MariaDB-0+deb13u1`。MySQL 8.4 兼容性以 Phase E 远程 CI 四矩阵为准；本阶段未删除或修改生产库结构。

## 5. 未关闭项（OPEN）

| ID | 说明 |
|---|---|
| R-16 | 迁移测试已扩展到全部 20261128 版本，但 `001-027` 的逐版本 checksum 内容断言仍未覆盖全部字段细节 |
| R-21 | `20261027_verification.sql` 仍是不在版本化 runner 中的一次性脚本，需人工决定是否纳入 |
| R-22 | `bin/test.sh` 的 `TEST_DB` 可指向任意库并被 `--fresh` 删除；应限定测试库前缀并探测失败即退出 |
| 性能 | 索引/N+1/大表 DDL 评估未完成，列 Phase D/E |
