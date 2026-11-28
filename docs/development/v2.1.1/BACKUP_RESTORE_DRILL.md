# Phase 7 — 备份加密、完整性与隔离恢复演练报告

基线：Phase 6 提交 `575c4d3`，分支 `feature/v2.1.1-production-hardening`。
标注：**CONFIRMED**（实跑证据）/ **OPEN**（未实现或未量化）。

## 1. 备份链路改造（CONFIRMED）

- `bin/backup.php`：新增可选 `BACKUP_ENCRYPTION_KEY`（至少 16 字符）。配置后用 `hash('sha256', key, true)` 派生 AES-256 密钥，逐产物生成 16 字节随机 IV，以 `aes-256-cbc` 加密为 `*.enc`（权限 0600）并删除明文。
- `manifest.json`：`files` 段记录明文 `sha256/bytes`，加密时另记录 `encrypted/iv/enc_sha256/enc_bytes`；顶层记录 `encryption=aes-256-cbc` 与 `key_fingerprint`（`hash('sha256', key)` 前 16 位）。**密钥本体不写入备份目录、manifest 或 Git**。
- `bin/restore-check.php`：加密备份要求提供同一 `BACKUP_ENCRYPTION_KEY` 且指纹匹配；先验密文 sha256/bytes，再解密到临时文件校验明文 sha256/bytes，最后 gzdecode 统计 `CREATE TABLE` / `INSERT INTO`（要求 tables>0 且 inserts>0）。错误密钥、缺失密钥、密文篡改均 exit 1。
- `bin/doctor.php`：新增非阻断备份加密提示（未配置 key 时 warn）。
- `.env.example`、`docs/deployment.md`、`docs/security.md`：同步密钥要求、核验命令与边界说明。

## 2. 自动化测试（CONFIRMED）

新增 `P7：加密备份需正确密钥核验，错误密钥与篡改均失败`：
- 以测试密钥执行 `bin/backup.php --keep=3`，断言目录、manifest 加密标记与指纹、明文已删除且 `*.enc` 存在、manifest 不含密钥；
- 正确密钥 `restore-check` rc=0；错误密钥 rc=1；篡改密文首字节后 rc=1；测试结束清理备份目录。
- `bash bin/test.sh` rc=0，**166 通过 / 0 失败**，日志 `/tmp/opencode/v2.1.1/phase7/integration.log`。

## 3. 隔离恢复演练（CONFIRMED，非生产）

实际执行命令与结果记录在 `/tmp/opencode/v2.1.1/phase7/drill/`：

| 步骤 | 结果 |
|---|---|
| `BACKUP_ENCRYPTION_KEY=... php bin/backup.php --keep=5` | rc=0，备份目录 `storage/backups/20261128-211853` |
| 正确密钥 `php bin/restore-check.php <dir>` | rc=0 |
| 错误密钥 `php bin/restore-check.php <dir>` | rc=1（符合预期） |
| 用 manifest 中的 IV + 密钥派生值 `openssl enc -d -aes-256-cbc` 解密 `database.sql.gz.enc` | rc=0 |
| 导入隔离库 `jidian_restore`（`gunzip | mysql`） | rc=0 |
| 行数核对 | `users 8/8`、`memorials 5/5`、`schema_migrations 27/27`、`families/family_persons/archives/ai_workflows 0/0`（源库本身为 0） |
| `DB_DATABASE=jidian_restore php bin/doctor.php` | rc=0，无阻断；警告仅为环境项（无 BACKUP_ENCRYPTION_KEY、upload/post 限制） |
| 删除隔离库 `jidian_restore` | rc=0 |

结论：加密备份可独立解密、导入并在隔离库中通过 doctor 与行数核对；**未触碰生产数据库**。

## 4. RPO / RTO

- **RPO**：生产现有 root crontab 每日 04:17 `backup.php --keep=14`；本次改造后若配置 `BACKUP_ENCRYPTION_KEY`，每个备份点即为可恢复点。RPO 上限即备份间隔（24 小时），**OPEN：尚未配置异地同步，也未按任务书量化到分钟级**。
- **RTO**：本次演练从解密、导入到 doctor 通过为分钟级（导入小数据量），**OPEN：未在真实数据量下计时，也未进行生产切换演练**。

## 5. 剩余风险 / OPEN

- 脚本**不自动异地上传**；异地存储需运维侧对象存储/多副本同步，密钥必须与备份分离存放。
- `schema.sql` 仍不含所有迁移字段（设计如此），恢复依赖按序执行 `migrate-v2.php`；演练使用完整 mysqldump 故包含迁移表。
- 备份不包含 `.env` 与 `storage/logs`（敏感且可由部署配置重建），需在运维手册中明确保管责任。
- 加密依赖 `openssl` 扩展与正确密钥管理；密钥丢失将无法恢复，需另行建立密钥托管流程（OPEN）。

## 6. 门禁

PHP lint rc=0；PHPStan Level 5 `[OK] No errors` rc=0；secret-scan rc=0；node --check rc=0；迁移 38 版本全 applied + 重复执行 rc=0；doctor rc=0（0 阻断，4 警告）；`git diff --check` rc=0。
