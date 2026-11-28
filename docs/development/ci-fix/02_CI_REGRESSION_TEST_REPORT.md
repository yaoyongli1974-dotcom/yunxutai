# Phase 2 本地全量回归报告

按 CI 顺序执行，日志位于 `/tmp/opencode/ci-fix/`。

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' -print0 | xargs -0 -n1 php -l` | PASS (rc=0) |
| Composer 校验 | `composer validate --strict` | 首轮 rc=1（缺 license 警告）；补 `"license": "proprietary"` 后复验 PASS |
| Composer 安装 | `composer install --no-interaction --prefer-dist --no-progress` | PASS (rc=0) |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | PASS，`[OK] No errors` |
| 自定义 Secret Scan | `bash bin/secret-scan.sh` | PASS，rc=0，无误报 |
| Gitleaks | gitleaks action（CI） | 本地无二进制，未伪造结果；由 CI 实际步骤验证（最终 4 个矩阵均 PASS） |
| 小程序 JS | `find miniprogram -name '*.js' ... node --check` | PASS (rc=0) |
| 集成测试 | `bash bin/test.sh` | PASS，128 通过 / 0 失败 |
| Migration | `php bin/migrate-v2.php --status` / 执行 / 再次 `--status` | PASS，rc=0，重复执行安全 |
| Doctor | `php bin/doctor.php`（prod-like upload limits） | PASS，4 条环境警告（APP_DEBUG/APP_ENV 等），0 阻断 |

结论：本地质量门禁全部通过；唯一修复项为 composer.json 补 license 字段（闭源 proprietary，与 LICENSE 一致）。未删测试、未降 PHPStan level、未扩大 allowlist、未改业务行为。

Phase 3 四矩阵（PHP 8.4/8.5 × MySQL 8.4/MariaDB 11）已通过 CI 实跑验证，见 `03_CI_MATRIX_REPORT.md`。

## 最终代码状态复验（代码 HEAD `58ccf98a9db75882371b1901d857f3725490964a`，其后仅有文档提交）

在全部代码修复完成后，按 CI 顺序于最终 HEAD 重新执行完整本地验证，日志位于 `/tmp/opencode/ci-fix/final/`：

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' -print0 \| xargs -0 -n1 php -l` | rc=0，PASS |
| Composer 校验 | `composer validate --strict` | rc=0，`./composer.json is valid`（composer 自身的 PHP 弃用提示不影响结论） |
| Composer 安装 | `composer install --no-interaction --prefer-dist --no-progress` | rc=0，PASS，未改动 composer.lock/vendor |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | rc=0，`[OK] No errors` |
| 自定义 Secret Scan | `bash bin/secret-scan.sh` | rc=0，无误报 |
| Gitleaks | CI 实际步骤 `gitleaks/gitleaks-action@v3` | 4/4 matrix PASS（本地无二进制，不伪造结果） |
| 小程序 JS | `find miniprogram -name '*.js' -print0 \| xargs -0 -n1 node --check`（Node v24.15.0） | rc=0，PASS |
| 集成测试 | `bash bin/test.sh` | rc=0，128 通过 / 0 失败 |
| Migration | `php bin/migrate-v2.php --status`、重复执行后再次 `--status` | 27 个迁移全部 applied，rc=0，幂等且 checksum 一致（runner 对已 applied 且 checksum 相同的版本跳过） |
| Doctor | `php -d upload_max_filesize=20M -d post_max_size=25M bin/doctor.php` | rc=0，0 阻断，2 条环境警告（APP_DEBUG/APP_ENV） |

远程验收基线 run：`46108326290`（`58ccf98`）completed/success，4/4 job、全部 step success，check run 注解数 0；其后的纯文档提交不改变代码状态，以最新 run 为准。仓库 main 未配置 branch protection/required checks（GitHub API 返回 `Branch not protected`），CI 是当前唯一检查且已全绿。
