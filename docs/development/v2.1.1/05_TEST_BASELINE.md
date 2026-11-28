# 云叙台 v2.1.1 — Phase 0 测试基线

基线提交：`e723d07`（行为等价于 v2.1.0 `ff23bc5`）
环境：本地 MariaDB 11.8.9，PHP 8.4.28，Node 24，PHPStan 2.x。
日志目录：`/tmp/opencode/v2.1.1/phase0/`。

## 1. 命令与结果（全部实跑）

| 项目 | 命令 | 结果 | 证据 |
|---|---|---|---|
| 集成测试 | `bash bin/test.sh` | rc=0，**145 通过 / 0 失败** | `integration.log`、`test-timing.txt`（49 秒） |
| PHP 语法（关键文件） | `php -l app/bootstrap.php`、`app/routes.php`、`app/routes-api.php` | 3× rc=0 | `php-lint-key.txt` |
| Composer 安装 | `composer install --no-interaction --prefer-dist --no-progress` | rc=0 | `composer-install.log` |
| Composer 校验 | `composer validate --strict` | rc=0，`./composer.json is valid`；仅 composer 自身 `E_STRICT` 弃用提示 | `composer-validate.log` |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | rc=0，`[OK] No errors`（Level 5） | `phpstan.log` |
| 自定义 Secret Scan | `bash bin/secret-scan.sh` | rc=0，未发现疑似凭据 | `secret-scan.log` |
| 小程序/JS 语法 | `find miniprogram public/assets/js -name '*.js' -print0 \| xargs -0 -n1 node --check` | rc=0 | `js-check.log` |
| Migration 状态 | `DB_DATABASE=jidian_test php bin/migrate-v2.php --status` | rc=0，32 版本全 applied（含 20261128_001–005） | `migrate-status.log` |
| Doctor | `APP_ENV=testing APP_DEBUG=true php bin/doctor.php` | rc=0，0 阻断，4 警告 | `doctor.log` |

Doctor 4 条非阻断警告：`APP_DEBUG=true`、`APP_ENV=testing`、`upload_max_filesize=2M`、`post_max_size=8M`。这些是测试环境默认配置，不计为改造引入的失败。

## 2. 测试构成

`tests/run.php` 共 145 项，覆盖单元、公开页面、账号权限、互动审核、隐私媒体、小程序 API、KV/Redis、邮件通知、Round2/B/C/D/E 各阶段补充项，以及 Phase E 的 10 Worker 并发领取与 AI 完成通知测试。并发辅助脚本：`tests/concurrency/rate.php`、`tests/concurrency/claim-once.php`。

## 3. 未执行 / 需后续环境

- **未执行**：真实 MySQL 8.4 本地集成（CI 四矩阵覆盖）；gitleaks 二进制本地扫描（CI 覆盖）；微信小程序真机/开发者工具走查；100 家庭/10000 人物/100000 档案规模性能数据。
- 上述项在 Phase 0 明确标为 **未执行**，不会在报告中写“已通过”。后续阶段按任务书使用 CI 或真实环境补齐。

## 4. 结论

改造前基线为 145/0 全绿，无既有红灯。任何后续阶段出现的新失败都必须归因于本轮改动；本基线证据可用于对比。
