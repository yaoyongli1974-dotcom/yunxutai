# Phase 2 本地全量回归报告

按 CI 顺序执行，日志位于 `/tmp/opencode/ci-fix/`。

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' -print0 | xargs -0 -n1 php -l` | PASS (rc=0) |
| Composer 校验 | `composer validate --strict` | 首轮 rc=1（缺 license 警告）；补 `"license": "proprietary"` 后复验 PASS |
| Composer 安装 | `composer install --no-interaction --prefer-dist --no-progress` | PASS (rc=0) |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | PASS，`[OK] No errors` |
| 自定义 Secret Scan | `bash bin/secret-scan.sh` | PASS，rc=0，无误报 |
| Gitleaks | gitleaks action（CI） | 本地无二进制，未伪造结果；CI run 46103003877 该步骤 PASS（allowlist 生效），待推送后 Phase 4 复验 |
| 小程序 JS | `find miniprogram -name '*.js' ... node --check` | PASS (rc=0) |
| 集成测试 | `bash bin/test.sh` | PASS，128 通过 / 0 失败 |
| Migration | `php bin/migrate-v2.php --status` / 执行 / 再次 `--status` | PASS，rc=0，重复执行安全 |
| Doctor | `php bin/doctor.php`（prod-like upload limits） | PASS，4 条环境警告（APP_DEBUG/APP_ENV 等），0 阻断 |

结论：本地质量门禁全部通过；唯一修复项为 composer.json 补 license 字段（闭源 proprietary，与 LICENSE 一致）。未删测试、未降 PHPStan level、未扩大 allowlist、未改业务行为。

待批准：Phase 3 四矩阵（PHP 8.4/8.5 × MySQL 8.4/MariaDB 11）分别 PASS 并出 03 报告。
