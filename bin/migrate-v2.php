#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 云叙台 2.0 增量迁移执行器：按版本号顺序执行 database/migrations/*.php。
 * 用法：php bin/migrate-v2.php [--status]
 *   --status  仅列出版本记录，不执行任何写入。
 *
 * 幂等策略：已 applied 且 checksum 一致的版本跳过；failed 版本允许原样重试。
 * MySQL DDL 会隐式提交，因此每个迁移自身必须使用 IF NOT EXISTS / 前置检查保证可重跑。
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$statusOnly = in_array('--status', $argv ?? [], true);
$database = (string) config('db.database');
echo "云叙台 2.0 迁移" . ($statusOnly ? '（状态查询）' : '') . "\n数据库：{$database}\n\n";

try {
    if (!$statusOnly) {
        Db::query(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME DEFAULT NULL,
                status ENUM(\'applied\',\'failed\') NOT NULL DEFAULT \'failed\',
                error TEXT DEFAULT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $files = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
    sort($files);

    if ($statusOnly) {
        $hasTable = (int) Db::value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['schema_migrations']
        ) > 0;
        if (!$hasTable) {
            echo "尚无 schema_migrations 表。\n";
            exit(0);
        }
        $rows = Db::fetchAll('SELECT version, name, status, executed_at, error FROM schema_migrations ORDER BY version');
        if ($rows === []) {
            echo "尚无迁移记录。\n";
        }
        foreach ($rows as $row) {
            echo sprintf(
                "  %s  %-28s %-8s %s%s\n",
                $row['version'],
                $row['name'],
                $row['status'],
                $row['executed_at'] ?? '—',
                $row['error'] ? '  ' . $row['error'] : ''
            );
        }
        exit(0);
    }

    echo '发现 ' . count($files) . " 个迁移文件。\n\n";
    foreach ($files as $file) {
        $migration = require $file;
        if (!is_array($migration) || !is_string($migration['version'] ?? null)
            || !preg_match('/^\d{8}_\d{3}$/', $migration['version'])
            || !is_string($migration['name'] ?? null)
            || !is_array($migration['sql'] ?? null)) {
            throw new RuntimeException('迁移文件格式错误：' . basename($file));
        }
        $version = $migration['version'];
        $name = $migration['name'];
        $checksum = hash('sha256', (string) file_get_contents($file));
        $record = Db::fetch('SELECT checksum, status FROM schema_migrations WHERE version = ?', [$version]);

        if ($record !== null && $record['status'] === 'applied') {
            if (!hash_equals((string) $record['checksum'], $checksum)) {
                throw new RuntimeException("迁移 {$version} 内容已变更，禁止重复执行：{$name}");
            }
            echo "· {$version} {$name} 已执行，跳过\n";
            continue;
        }

        try {
            foreach ($migration['sql'] as $sql) {
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException('迁移包含空语句');
                }
                Db::query($sql);
            }
            if ($record === null) {
                Db::insert('schema_migrations', [
                    'version' => $version,
                    'name' => $name,
                    'checksum' => $checksum,
                    'executed_at' => date('Y-m-d H:i:s'),
                    'status' => 'applied',
                    'error' => null,
                ]);
            } else {
                Db::update(
                    'schema_migrations',
                    ['checksum' => $checksum, 'executed_at' => date('Y-m-d H:i:s'), 'status' => 'applied', 'error' => null],
                    'version = ?',
                    [$version]
                );
            }
            echo "✓ {$version} {$name} 执行完成\n";
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            if ($record === null) {
                Db::insert('schema_migrations', [
                    'version' => $version, 'name' => $name, 'checksum' => $checksum,
                    'executed_at' => null, 'status' => 'failed', 'error' => $error,
                ]);
            } else {
                Db::update('schema_migrations', ['status' => 'failed', 'error' => $error], 'version = ?', [$version]);
            }
            throw new RuntimeException("迁移 {$version} 失败：{$e->getMessage()}", 0, $e);
        }
    }

    echo "\n全部迁移已就绪。\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n迁移中止：" . $e->getMessage() . "\n");
    exit(1);
}
