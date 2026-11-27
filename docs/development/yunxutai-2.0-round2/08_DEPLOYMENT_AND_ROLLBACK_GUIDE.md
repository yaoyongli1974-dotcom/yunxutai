# Round2 部署与回滚指南

## 部署前
1. 确认本地全量测试通过、CI 矩阵至少 PHP 8.4 + MySQL 8.4 通过。
2. 生产库只读核对当前迁移版本；确认 027 未应用。
3. 执行 `php bin/backup.php` 生成数据库 + 上传文件备份，并用 `php bin/restore-check.php <backup.sql.gz>` 校验。
4. 将备份下载到隔离存储，核对 sha256。

## 部署
1. 上传变更代码（不覆盖生产 `.env`）。
2. 执行 `php bin/migrate-v2.php`，核对 `schema_migrations` 27 个版本全部 applied、failed=0。
3. 清 opcache；启动/重启 2.0 Worker：`systemctl restart ai-workflow-worker`（单元见 `deploy/ai-workflow-worker.service`）。
4. 冒烟：`/`、`/api/v1/me`、家族页、`bin/doctor.php`、Worker 日志心跳。

## 回滚
1. 停止 Worker，保留当前日志。
2. 用备份恢复数据库（先恢复到隔离库核对行数，再切换）。
3. 恢复上一版代码与 `.env`；清 opcache。
4. 核对旧纪念馆数据：`memorials`、`users`、`media` 行数与备份一致。
5. 迁移 027 为 ADD COLUMN，回滚时可通过受控 ALTER DROP COLUMN 撤销，但必须先确认无新代码依赖。

## 禁止
- 不直接在生产库手工改结构。
- 不修改已应用迁移文件或 checksum。
- 不在未授权情况下推送 main 或部署。
