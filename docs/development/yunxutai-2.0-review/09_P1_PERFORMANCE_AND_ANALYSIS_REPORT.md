# P1 修复报告：TLS、静态分析与性能

## 修改/新增
- `app/Core/Notify.php` / `app/Core/Kv/RespClient.php`：显式 SSL context、TLSv1.2/1.3、none+username 拒绝。
- `composer.json`（仅 dev 依赖）、`phpstan.neon`（level 5 + baseline）、`phpstan-baseline.neon`、`rector.php`、`.gitignore`、CI 增加 composer/PHPStan。
- `app/Models/Memorial.php`：counts 单 SQL 标量子查询。
- `app/Services/ArchiveService.php`：listForFamily SQL 层可见性过滤。
- `app/Controllers/Admin/ModerationController.php`：批量 WHERE id IN。
- `app/Core/Db.php`（P0）：列名白名单校验。
- `bin/install.php`：不再输出明文管理员密码。

## FULLTEXT ngram 兼容性决策
MySQL 8 的 `FULLTEXT ... WITH PARSER ngram` 在 MariaDB 11 不支持，而 CI 矩阵包含 MariaDB 11。为避免迁移在任一受支持数据库失败，**暂缓 ngram 全文索引**，保持现有 LIKE 查询 + SQL 层可见性过滤；待确定最低引擎版本或引入独立兼容层后再加迁移。

## 测试
`bash bin/test.sh`：128 通过 / 0 失败；php -l 通过。
