# Round 2 Phase 1 报告：API 认证与原子限流

## 修改文件
- `app/Core/Router.php`：`api-auth` / `api-admin` 中间件移除 `Auth::check()` Session 回退，只接受 Bearer Token；失败统一 401 JSON。
- `app/Core/ApiAuth.php`：`bearerToken()` 增加 64 位 hex 格式校验；保留 hash/撤销/过期/active 校验与 `Auth::setApiUser()`。
- `app/Core/RateLimiter.php`：`hit()` 改用 `CounterStore::consume()` 原子判定；存储异常 fail-closed（error_log 后拒绝）。
- `app/Core/RateLimitKeys.php`（新增）：场景化 key，身份/资源一律 sha256，不保存明文 IP/token/密码。
- `app/Core/Kv/CounterStore.php`：接口新增 `consume(key,max,window)`，旧方法保持兼容。
- `app/Core/Kv/MysqlCounter.php`：单条 UPSERT 条件表达式创建/重置/超限不计数/不续期；用同一连接的 `ROW_COUNT()` 判定本次是否放行。
- `app/Core/Kv/RedisCounter.php`：EVAL Lua 脚本原子实现 GET/TTL/SET EX/INCR，超限不 INCR。
- 16 处限流调用点改为场景 key；补 `captcha`、`wechat_login`、`invitation_accept`、`ai_workflow_create`、`upload` 场景。
- `tests/Support/MockRespServer.php`：Mock RESP 增加 `EVAL` 分支，模拟 Lua 限流语义。
- `tests/run.php`：新增 4 项测试；修正 2FA key 清理逻辑。
- `tests/concurrency/rate.php`（新增）：并发限流子进程。

## 关键函数
- `CounterStore::consume()`：单次原子消费一个额度。
- `MysqlCounter::consume()`：UPSERT 后立刻读取 `ROW_COUNT()`，0 表示超限、1/2 表示放行。
- `RedisCounter::consume()`：Lua 中一次完成判断与计数，避免 INCR + EXPIRE 分离。
- `RateLimitKeys::make()`：统一业务场景 key，避免明文敏感信息落库。

## 数据库迁移
无（复用 `throttle` 表唯一键 `throttle_key`）。

## 测试结果
`bash bin/test.sh`：**113 通过 / 0 失败 / 0 跳过**（原 109 项全部保留）。
新增覆盖：Web Session 不能访问 API、无效/撤销/过期 Token 401、Web 写 CSRF、100 并发最多放行 10。

## 尚未解决
- Redis 测试使用 Mock RESP 的 EVAL 模拟，尚未连接真实 Redis 服务验证 Lua 与重启恢复。
- 部分业务入口限流阈值沿用旧值，需按生产流量复核。
- 窗口过期语义依赖 MySQL `reset_at` / Redis TTL，长期时钟偏差需监控。

## 阶段门禁
Phase 1 通过；可进入 Phase 2（AI 工作流可靠性）。
