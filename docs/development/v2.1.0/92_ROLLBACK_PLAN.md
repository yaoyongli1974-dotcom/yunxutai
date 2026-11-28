# Phase E — 回滚方案（v2.1.0）

原则：所有回滚操作先备份、后执行；不直接修改生产数据库；不在未授权情况下操作生产服务器。

## 1. 代码回滚（发布前/RC）

- 本轮所有开发在功能分支 `feature/v2.1.0-production-hardening`，未合并 main。
- 若 PR 验证失败：关闭 PR 或追加修复提交；不需要回滚 main。
- 若已合并并需要回退：使用 `git revert <merge-commit>` 生成反向提交，保留历史；禁止对用户已提交历史强制推送。
- 版本回滚：将 `.env.example`、`config/config.php`、`README.md`、`SECURITY.md`、`tests/run.php` 的版本断言恢复为 v2.0.1（历史 tag v2.0.1 仍存在）。

## 2. 配置回滚

- APP_DEBUG 默认 false：如需恢复旧行为，在 `.env` 显式设置 `APP_DEBUG=true`（生产禁止）；配置层 production+debug fail-closed 需同时移除该保护，不建议。
- APP_KEY fail-closed：设置强随机 APP_KEY 即可通过；不要回退到开发占位符。
- APP_TRUSTED_PROXY：设为 false 恢复严格 Cookie Secure 判定；生产 TLS 代理环境应设为 true 并确保代理覆盖 `X-Forwarded-Proto`。
- no-store 媒体缓存：恢复 `private, max-age=300` 会降低撤销即时性，不建议；如确需回滚需同时接受 N-07 风险扩大。

## 3. 数据库迁移回滚

- 新增迁移：20261128_001（invitation token_hash）、002（事实来源字段）、003（归档清理队列）、004（审计 request_id）、005（性能索引）。
- 均为**追加型**变更，无删除列、无改字段语义、无修改已发布迁移；旧版本代码在新增列/表存在时仍可运行（除 005 索引为纯性能）。
- 回滚策略：
  1. 优先回退应用代码到 v2.0.1，不删除新增列/表；追加型 schema 对旧代码兼容。
  2. 若必须清理 schema，需在维护窗口备份后单独编写**新迁移**删除列/表/索引，禁止直接改历史迁移。
  3. `schema_migrations` 中对应版本记录需在确认无依赖后再处理；不要手工改 checksum。
- 归档异步清理队列：若 worker 异常，可暂停 worker、将 pending/processing 项重置为 pending（幂等重跑），或保留队列等待修复；不会自动删除物理文件。

## 4. AI Worker 与通知

- 新 fencing 逻辑回滚：恢复旧 `process()` 会重新引入旧 Worker 覆盖风险，不推荐；可先停止 Worker 再回退。
- 完成通知去重依赖 `notifications.link`；回退时不删除已发通知。

## 5. 备份与恢复

- 回滚前执行 `php bin/backup.php` 并用 `php bin/restore-check.php <backup-dir>` 校验 rc=0。
- 恢复演练仅在隔离库进行；严禁把演练导入生产库。
- 详见 `05_BACKUP_RECOVERY_REPORT.md` 与 `docs/deployment.md`。

## 6. 发布后监控要点

- 观察 `audit_logs.request_id` 写入率、`notifications` 中 `ai_workflow_completed` 是否重复/缺失、`archive_cleanup_queue` 是否持续 pending/failed、doctor 是否有未应用迁移、媒体 404 是否异常上升。

## 7. 决策与授权边界

- 生产迁移、部署、Nginx 边缘 CSP 启用、备份加密/异地配置均需项目负责人明确授权；本计划只描述方案，不代为执行。
