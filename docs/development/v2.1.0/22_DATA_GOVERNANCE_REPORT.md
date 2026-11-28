# 云叙台 v2.1.0 — 数据治理报告（Phase C）

## 1. 交付改动

| 项 | 文件 | 行为 |
|---|---|---|
| R-04 归档删除清理 | `app/Services/ArchiveService.php`、`database/migrations/20261128_003_archive_cleanup_queue.php`、`bin/archive-cleanup.php` | 采用用户确认的“先标记待清理 + 独立幂等任务异步清理”方案：审批只改状态并入队，不立即删物理文件；worker 可重复运行、复核档案状态、保护共享文件、失败重试/终止并写审计 |
| 数据保留策略 | `docs/development/v2.1.0/04_DATA_RETENTION_POLICY.md` | 记录当前真实保留/删除行为与 OPEN 项，不虚构法律保留期 |
| 备份与恢复 | `bin/backup.php`、`bin/restore-check.php`、`docs/deployment.md`、`05_BACKUP_RECOVERY_REPORT.md` | manifest 加 sha256/bytes；restore-check 严格校验；完成一次非生产完整恢复演练 |

## 2. 归档删除队列设计

- 表 `archive_cleanup_queue`：`UNIQUE(archive_id, storage_key)` 防重复入队；`KEY(status, attempts)`；FK → archives ON DELETE CASCADE。
- worker：`--limit`（默认 20，上限 100）、`--once`、`--max-attempts`（默认 3）；领取使用事务 + `FOR UPDATE SKIP LOCKED` + 条件 UPDATE，`attempts` 在领取时 +1。
- 幂等：重复运行不二次删除；物理文件缺失视为成功；共享 `storage_key` 只标 `done`；每次结果写 `Audit::log('archive.file_cleanup', …)`。
- 回滚：审批后、worker 执行前拒绝删除会移除未 `done` 队列项；已 `done` 表示物理文件已删除，不可回滚，需依赖备份。

## 3. 验证证据

- `C4：归档审批入队，worker 幂等清理且不动共享文件` PASS：审批后队列 1 条 pending、`archive_files` 与物理文件仍在；`--once` 后队列 done、文件行 0、物理文件不存在；二次 `--once` rc=0 且无新增队列。
- 备份演练：`bin/backup.php` rc=0；`restore-check` 目录模式 rc=0（3 产物 sha256/bytes OK，tables=57 inserts=53）；隔离库 `jidian_restore` 导入后 Doctor rc=0，关键表行数核对完成，演练库已删除。
- restore-check 负向夹具：篡改 sha256、零 INSERT 均 rc=1；legacy 有 INSERT rc=0、无 INSERT rc=1。
- 全量集成 138/0；迁移 31 版本全 applied；PHP lint/PHPStan/secret-scan/node check 全绿。

## 4. 开放项（OPEN）

1. 用户注销、导出文件过期、AI 中间文件、审计日志保留期需人工定义。
2. 备份无内置加密、默认本地单点；异地加密与轮转策略需运维确认。
3. 数据导出范围与授权撤销后的后台任务重检未在本阶段新增测试；列 E。
4. 本次未删除任何生产数据，未修改生产数据库。
