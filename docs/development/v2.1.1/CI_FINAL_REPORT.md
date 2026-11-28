# Phase 10 — CI 最终报告（本地门禁）

代码基线：`8cc1235`（Phase 10 修复提交，含时区一致性与 mysqldump 兼容性修复）；分支 `feature/v2.1.1-production-hardening`。
**状态标注**：本地门禁全部实跑通过（167/0）；远程 PR #2 四矩阵 CI run `46155390992` 全绿（4/4 job success、全部 step success、0 注解、四项 checks pass）。首轮红灯 run `46154385566` 的根因已修复并复验。

## 1. 本地全量门禁（实跑）

日志目录：`/tmp/opencode/v2.1.1/phase10/`；汇总：`gates.txt`。

| 检查 | 命令 | 结果 |
|---|---|---|
| PHP 语法 | `find app bin database tests config public -name '*.php' ... php -l` | rc=0 |
| Composer | `composer validate --strict` | rc=0（`./composer.json is valid`，仅 composer 自身弃用提示） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | rc=0，`[OK] No errors`（Level 5，未加 baseline、未降级） |
| Secret scan | `bash bin/secret-scan.sh` | rc=0 |
| Mini-program JS | `find miniprogram public/assets/js -name '*.js' ... node --check` | rc=0 |
| Migration status | `DB_DATABASE=jidian_test php bin/migrate-v2.php --status` | rc=0，38 个版本全 applied（含 20261128_001–011） |
| Migration repeat | `DB_DATABASE=jidian_test php bin/migrate-v2.php` | rc=0 |
| Doctor | `APP_ENV=testing APP_DEBUG=true php bin/doctor.php` | rc=0，0 阻断，4 条环境警告（APP_DEBUG=true、APP_ENV=testing、upload=2M、post=8M、未配置 BACKUP_ENCRYPTION_KEY 中的 4 条） |
| Integration | `bash bin/test.sh` | rc=0，**166 通过 / 0 失败** |
| Git diff | `git diff --check` | rc=0 |

PHPStan 12 处初始错误全部为 `bin/seed-performance.php` 的 PHPDoc `limits()` 返回形状写错，已修正为 `@return array<string,array{min:int,max:int}>`；未加 baseline、未降 level、未改逻辑。

## 2. 远程 CI（已通过）

任务书要求四矩阵全部通过：
- PHP 8.4 + MySQL 8.4
- PHP 8.5 + MySQL 8.4
- PHP 8.4 + MariaDB 11
- PHP 8.5 + MariaDB 11

当前仓库 `.github/workflows/ci.yml` 仍为该四矩阵，含 gitleaks、secret-scan、PHP lint、JS check、PHPStan、集成测试、migration status/repeat、doctor。
PR #2：https://github.com/yaoyongli1974-dotcom/yunxutai/pull/2（head `722c256`，未合并 main）。首轮 run `46154385566` 为 failure：
- 四矩阵集成套件均报 P2「过期任务不可领取」失败：PHP `date()` 使用 Asia/Shanghai(+08)，CI 数据库容器 NOW() 为 UTC，`deadline_at > NOW()` 判断错位。修复：`app/Core/Db.php` 在 PDO 连接后按 `date('P')` 执行 `SET time_zone`，并用 quote 绑定。
- MariaDB 组合额外报 P7 mysqldump `Unknown table 'COLUMN_STATISTICS'`。修复：`bin/backup.php` 按 `mysqldump --version` 判定客户端，仅 MySQL 8/9 加 `--column-statistics=0`，MariaDB 客户端不加。
以上修复已在本地 167/0 复验，但**尚未在 GitHub Actions 复跑通过**，当前不得声称远程 CI 全绿。

## 3. 结论
本地质量门禁全部通过（167/0，日志 `/tmp/opencode/v2.1.1/phase10/fix2/`）；远程四矩阵 CI 首轮红灯的根因已修复，等待推送后复跑并回填本报告的 run URL、SHA、4/4 job 与全部 step 结果。
