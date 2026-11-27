# Round2 数据库迁移报告

- 历史迁移 001~026 未修改，checksum 保持不变。
- Round2 新增迁移：`20261127_027_ai_workflow_locking`（`ai_workflows` 增加 `lock_version`、`heartbeat_at`、`last_error_at`），已同步 `database/schema.sql`。
- 迁移执行器 `bin/migrate-v2.php` 保留版本/checksum/失败重跑机制；MySQL DDL 隐式提交，不具备完整 DDL 事务回滚。
- 测试库全新安装 + 二次执行：27 个迁移全部 applied，重复执行全部跳过；旧表 `memorials/users/media` 行数不变。
- 生产库已执行 026（上一轮授权部署），027 尚未在生产执行。
- 新增结构均通过独立迁移，无直接改生产结构。
