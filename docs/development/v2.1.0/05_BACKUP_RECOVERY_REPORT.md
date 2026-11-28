# 云叙台 v2.1.0 — 备份与恢复演练报告（Phase C）

## 1. 交付改动

| 文件 | 改动 | 证据 |
|---|---|---|
| `bin/backup.php` | `manifest.json` 新增 `files` 映射，逐产物写入 `sha256` + `bytes`；保留其他字段与 14 份轮转逻辑不变 | `php -l` rc=0；实跑 backup rc=0 |
| `bin/restore-check.php` | 目录模式：校验 manifest 中**所有**产物存在、sha256/字节一致，再 gunzip 统计 SQL 中 `CREATE TABLE`/`INSERT INTO`，要求 `tables>0` 且 `inserts>0`；单 gz legacy 模式也要求 inserts>0；新增 `--help`；不导入任何数据库 | `php -l` rc=0；`--help` rc=0；夹具测试见 §3 |
| `docs/deployment.md` | 记录 manifest 校验、restore-check 用法/退出码，以及隔离环境完整恢复演练步骤；明确 restore-check 不是完整恢复证明 | 文档 diff |

## 2. 本次演练（非生产环境）

环境与对象：本地 MariaDB 11.8.9；源库 `jidian_test`（集成测试库）；恢复库 `jidian_restore`（演练后已删除）。

```bash
DB_DATABASE=jidian_test APP_ENV=testing php bin/backup.php
# 备份目录：storage/backups/20261128-143459

php bin/restore-check.php storage/backups/20261128-143459
# rc=0；3 个产物 sha256/bytes 全部 OK；tables=57 inserts=53

gunzip -c storage/backups/20261128-143459/database.sql.gz > /tmp/.../restore.sql
mysql -h127.0.0.1 -uroot -e 'DROP DATABASE IF EXISTS jidian_restore; CREATE DATABASE jidian_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
mysql -h127.0.0.1 -uroot jidian_restore < /tmp/.../restore.sql
DB_DATABASE=jidian_restore APP_ENV=testing php bin/doctor.php
# rc=0，0 阻断，5 条环境警告
mysql -h127.0.0.1 -uroot -N -e '<按表统计行数>'
```

关键表恢复行数：

| 表 | 行数 |
|---|---:|
| users | 80 |
| memorials | 3 |
| families | 55 |
| archives | 13 |
| archive_files | 4 |
| ai_workflows | 9 |
| schema_migrations | 31（全部 applied） |

结论：数据库导出/压缩/校验/导入链路可用，恢复后 Doctor 0 阻断；文件归档产物经 manifest 校验一致。演练库已删除，未触碰生产。

## 3. restore-check 负向用例（临时夹具，已清理）

| 夹具 | 期望 | 实际 |
|---|---|---|
| 有效备份目录 | rc=0 | rc=0 |
| 篡改产物 sha256 | rc=1 | rc=1 |
| SQL 中零 INSERT | rc=1 | rc=1 |
| legacy 单 gz 且有 INSERT | rc=0 | rc=0 |
| legacy 单 gz 且无 INSERT | rc=1 | rc=1 |

## 4. 残余限制（OPEN）

1. `restore-check` 只做完整性检查，不能替代真实导入与业务验证；本次由人工按 `docs/deployment.md` 步骤完成隔离演练。
2. 备份仍无内置加密，且默认本地单点存储；异地加密与保留期需运维决策（`docs/security.md` 已记录）。
3. 演练使用 MariaDB 11.8.9；MySQL 8.4 的备份/恢复兼容性以 Phase E CI 与后续专项演练为准。
4. 本次未验证媒体 tar 包的逐文件行数/权限对比（仅 sha256/bytes 校验），完整文件级恢复检查列为后续运维项。
