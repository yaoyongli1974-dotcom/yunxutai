# Phase 3/4：完整 CI 矩阵报告

## 1. 验证对象

| 项 | 值 |
|---|---|
| 仓库 | `yaoyongli1974-dotcom/yunxutai` |
| 分支 | `main` |
| 最终修复提交 | `b2209708580241e4fa05908c95eb65c6ab7e640c` |
| 最终验证 run | `46107303591` |
| Run URL | https://github.com/yaoyongli1974-dotcom/yunxutai/actions/runs/46107303591 |
| 触发方式 | push to main |
| Run 结论 | `completed` / `success`（`gh run watch --exit-status` rc=0） |

## 2. 四矩阵结果

| PHP | 数据库 | Job | 状态 | 结论 |
|---|---|---|---|---|
| 8.4 | mysql:8.4 | `tests (8.4, mysql:8.4)` | completed | SUCCESS |
| 8.4 | mariadb:11 | `tests (8.4, mariadb:11)` | completed | SUCCESS |
| 8.5 | mysql:8.4 | `tests (8.5, mysql:8.4)` | completed | SUCCESS |
| 8.5 | mariadb:11 | `tests (8.5, mariadb:11)` | completed | SUCCESS |

四个 job 全部 `completed` / `success`，无 `cancelled`、无 `failure`，无仅凭单一组合代替其余组合的情况。

## 3. 全部 Step 结果

四个 job 的 step 序列完全一致，且**每一步均为 `success`，无 skipped、无 failure**：

1. Set up job
2. Initialize containers
3. Run actions/checkout@v4
4. Run shivammathur/setup-php@v2
5. Run actions/setup-node@v4
6. Install system tools
7. Wait for database (works for MySQL and MariaDB)
8. Secret scan (gitleaks)
9. Secret scan (local patterns)
10. PHP syntax check
11. Miniprogram JS syntax check
12. Static analysis (PHPStan level 5, baseline for legacy)
13. Full integration suite (fresh DB + migrations + security tests)
14. Migration status
15. Migration repeatability
16. Doctor (production-like upload limits)
17. Secret scan (no credentials in tracked source)
18. Post Run actions/setup-node@v4
19. Post Run actions/checkout@v4
20. Stop containers
21. Complete job

仓库当前仅有一个 workflow（`CI`）；该 SHA 仅有上述一个 run，不存在被隐藏的失败 run 或被掩盖的失败 job。

## 4. 从红灯到全绿的修复记录

推送 `03c5d09` 后 CI run `46105846362` 曾在 PHP 8.5 两组合失败：

- 失败步骤：`Static analysis (PHPStan level 5, baseline for legacy)`。
- 错误：`Ignored error pattern ... samesite ... was not matched in reported errors`（`ignore.unmatched`）与 `Offset 'samesite' on array{..., partitioned: bool} on left side of ?? always exists and is not nullable`（`nullCoalesce.offset`），共 2 个错误。
- 根因：PHP 8.5 的 `session_get_cookie_params()` stub 形状多出 `partitioned: bool`，PHP 8.4 冻结的基线正则无法匹配；根因代码是 `app/Core/Auth.php:97` 对必然存在的 `samesite` 键使用 `?? 'Lax'`。
- 修复（Fix #2）：删除多余兜底 `'samesite' => $params['samesite']`，并删除对应过期基线条目；未放宽基线、未降 PHPStan level、未跳过步骤、未改业务行为。详见 `01_CI_FIX_LOG.md` Fix #2。

## 5. 本地回归证据（修复后）

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `php -l app/Core/Auth.php` | PASS |
| PHPStan（本地 PHP 8.4.28） | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`，rc=0 |
| 自定义 Secret Scan | `bash bin/secret-scan.sh` | PASS，无误报 |
| 集成测试 | `bash bin/test.sh` | 128 通过 / 0 失败 |

PHP 8.5 未在本地安装（本地仅有 PHP 8.4 / 7.4），由 CI 矩阵实跑验证并全部通过。

## 6. 非阻断提示

CI 出现 Node.js 20 deprecated 注解（actions/checkout@v4、actions/setup-node@v4、gitleaks action 被强制运行于 Node.js 24），属平台侧警告，不影响任何步骤结论，不在本专项范围内；后续已单独升级并消除，见 `05_NODE24_ACTION_UPGRADE.md`。

## 7. 结论

四矩阵全绿、全部 Step 通过、无取消/隐藏失败，Phase 4 验收条件中的 CI 复跑部分满足；最终交付结论见 `04_CI_RELEASE_READINESS.md`。
