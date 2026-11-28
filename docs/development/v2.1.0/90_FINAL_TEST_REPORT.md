# Phase E — 最终测试报告

分支：`feature/v2.1.0-production-hardening`；目标版本：v2.1.0；基线 `b3dfc94`（Phase D 提交）。
本报告记录最终交付前的全量回归与恢复演练；远程四矩阵 CI 以 PR checks 为准（见 `91_RELEASE_READINESS.md`）。

## 1. 测试环境与基线

- 本地数据库：`11.8.9-MariaDB-0+deb13u1`（CI 另覆盖 MySQL 8.4 / MariaDB 11 × PHP 8.4 / 8.5）。
- 测试命令：`bash bin/test.sh`，日志 `/tmp/opencode/phase-e/integration2.log`。
- PR 首轮 CI（run `46119366823`）在 gitleaks 步骤失败：PR 浅克隆中缺少 `b526132^` base commit，导致 `git log ... base^..head` 返回 unknown revision。修复为 `.github/workflows/ci.yml` 的 checkout 增加 `fetch-depth: 0`；未关闭扫描、未改 allowlist、未改门禁。
-  harness 安全修复：`bin/test.sh` 强制 `TEST_DB` 以 `jidian_test` 开头，且服务器探测全部失败时直接 exit 1（R-22）。

## 2. 质量门禁（最终 HEAD）

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' \| xargs -n1 php -l` | rc=0，255 文件 |
| Composer | `composer validate --strict` | rc=0（`./composer.json is valid`，仅 composer 自身 PHP 弃用提示） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | rc=0，`[OK] No errors`，Level 5 未降；清理 R-18 修复后过期 baseline 条目 2 条 |
| Gitleaks / Secret Scan | CI 步骤 + `bash bin/secret-scan.sh` | 本地 rc=0；远程以 PR CI 为准 |
| 小程序 / 前端 JS | `find miniprogram public/assets/js -name '*.js' \| xargs -n1 node --check` | rc=0 |
| 集成测试 | `bash bin/test.sh` | rc=0，**145 通过 / 0 失败** |
| Migration | `php bin/migrate-v2.php --status` + 重复执行 | rc=0，**32 版本全 applied**；全量 checksum 与磁盘文件逐一比对通过 |
| Doctor | `APP_ENV=testing APP_DEBUG=true php bin/doctor.php` | rc=0，0 阻断，3 条环境警告 |
| Diff | `git diff --check` | rc=0 |
| 备份恢复 | `bin/backup.php` → `bin/restore-check.php` → 隔离库导入 → doctor → 行数核对 → 删除演练库 | 全部 rc=0 |

## 3. 测试增量

- Phase C 138 → Phase D 143 → Phase E **145**。
- Phase E 新增：`E：AI 工作流完成写入站内通知且只写一次`、`E：10 个 Worker 并发领取同一任务只有一个有效执行者`。
- Phase E 扩展：迁移 checksum 断言由 001–006 扩展到**全部迁移文件**（磁盘 SHA-256 与 `schema_migrations` 登记逐一匹配）；页脚版本断言改为 v2.1.0。

## 4. 并发验证

- **真实并发领取**：`tests/concurrency/claim-once.php` 由 `proc_open` 启动 10 个子进程，各自 `claimStrict()`；断言同一任务仅 1 个 Worker 领取、状态为 `processing` 且 `locked_by` 属于并发 Worker 之一。PASS。
- **订单并发确认**：Phase C 中 `AiOrderController::confirm()` 已改为事务 + `SELECT ... FOR UPDATE` + 条件状态校验；本轮用串行回归验证行为，10 路 HTTP 并发确认未纳入自动化（需管理员会话/CSRF 上下文，记为 NEEDS_VERIFICATION，见 `93_UNRESOLVED_RISKS.md`）。

## 5. 恢复演练（非生产）

- 备份目录：`storage/backups/20261128-145834`。
- `bin/restore-check.php`：3 个产物 sha256/bytes 全部 OK；`tables=56`、`inserts=19`，rc=0。
- 导入隔离库 `jidian_restore` 后 doctor rc=0；行数：`users=8`、`memorials=5`、`families=0`、`ai_workflows=0`、`schema_migrations=27`；演练库已删除。
- 说明：families/ai_workflows 为 0 是因为测试库在历史测试中被清理；演练验证的是备份校验与导入链路完整性，不代表生产数据量。

## 6. 结论

最终本地质量门禁全部通过，145 项集成测试覆盖认证、授权、隐私、媒体、家族、档案、AI、数据治理、审计、性能索引与并发领取关键路径。
远程四矩阵 CI 待 PR checks 验证；在获得 PR run 结果前不宣称远程全绿。
