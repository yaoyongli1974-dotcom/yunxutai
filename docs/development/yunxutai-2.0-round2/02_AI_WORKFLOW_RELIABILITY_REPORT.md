# Phase 2 报告：AI 工作流可靠性与 Provider 安全

状态：完成（本地），待人工验收；未推 main、未碰生产库。

## 修改文件
- `app/Services/AiWorkflowService.php`：`claimStrict()` 使用 `FOR UPDATE SKIP LOCKED` 原子领取并递增 `lock_version`；`heartbeat()`、`owns()`、`recoverExpired()`、`process()` 均带 fencing 校验；兼容旧 `claim()` 与旧 `process()` 调用。
- `app/Services/Ai/ProcessingClient.php`：重写 SSRF 防护、文件校验、协议适配与错误分类。
- `database/schema.sql`：同步迁移 027 字段。
- `tests/run.php`：新增 5 项 Phase 2 测试。

## 新增文件
- `database/migrations/20261127_027_ai_workflow_locking.php`
- `bin/ai-workflow-worker.php`（重写）：CLI-only、`--once/--limit/--max-runtime`、SIGTERM/SIGINT 优雅停止、SIGALRM 每 60s 续租、JSON 结构化日志。
- `deploy/ai-workflow-worker.service`：systemd 单元。
- 本报告。

## 关键函数
- `claimStrict(workerId, limit)`：事务内 SELECT SKIP LOCKED + 条件 UPDATE，返回 `[{id, lock_version, workflow_type}]`。
- `heartbeat(id, workerId, lockVersion)`：严格校验持锁权，MySQL rowCount=0 时用 `owns()` 兜底。
- `recoverExpired()`：条件 UPDATE，递增版本、写 last_error_at、重试退避与审计。
- `process(id, workerId, lockVersion)`：旧 worker 不可覆盖结果。
- `ProcessingClient::validateEndpoint()`：HTTPS/私网/元数据/DNS/rebinding 防护；`CURLOPT_RESOLVE` 固定 IP。
- `ProcessingClient::recognize()`：真实 MIME + 扩展名 + 大小 + 目录限制；错误分类与有限重试。

## 数据库迁移
仅新增迁移 027（`lock_version`、`heartbeat_at`、`last_error_at`），未修改已应用历史迁移；测试库全新安装与重复执行均通过。

## 测试
`bash bin/test.sh`：**118 通过 / 0 失败**（原 113 项全保留）；全量 PHP lint 0 错误。

## 未决
- 真实 Redis Lua 与 systemd 常驻运行未在本机验证。
- 生产 Worker 部署需另行授权。
- Provider 外部真实服务联调需管理员配置凭据。

## 门禁
可进入 Phase 3。
