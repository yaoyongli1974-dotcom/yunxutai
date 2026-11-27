# Round2 测试矩阵报告

| 层面 | 覆盖 | 证据 |
|---|---|---|
| 集成 | Web/API/权限/档案/AI/通知 | `bash bin/test.sh` 126 通过 / 0 失败 |
| 认证 | Session 不能替代 Bearer、无效/撤销/过期 token、CSRF | Round2 Phase 1 测试 4 项 |
| 并发限流 | 100 并发最多 10、窗口重置、超限不计数 | `tests/concurrency/rate.php` + xargs -P25 |
| AI 并发 | fencing/heartbeat/租约恢复/旧 Worker 不可覆盖 | Round2 C 测试 3 项 + Worker CLI |
| Provider 安全 | SSRF/私网/元数据/文件类型/错误分类 | Round2 D 测试 2 项 |
| 事务一致性 | 档案版本/删除状态机 | Round2 J 测试 1 项 |
| 语法 | PHP 全量、小程序与 Web JS | php -l / node --check 全通过 |
| CI 矩阵 | PHP 8.4/8.5 × MySQL 8.4/MariaDB 11 | `.github/workflows/ci.yml`（待首次运行验证） |

未覆盖：真实 Redis 实例的 Lua 原子性（测试用 Mock RESP）、真实触屏设备、GitHub Actions 矩阵首次运行结果。
