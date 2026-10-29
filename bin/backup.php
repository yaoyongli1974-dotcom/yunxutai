#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 备份数据库与上传目录。
 * 用法：php bin/backup.php [--keep=14] [--no-uploads]
 * 产物：storage/backups/YYYYmmdd-HHMMSS/{database.sql.gz,uploads.tar.gz}
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['keep::', 'no-uploads']);
$keep = max(1, (int) ($options['keep'] ?? 14));
$includeUploads = !isset($options['no-uploads']);

$backupRoot = BASE_PATH . '/storage/backups';
$stamp = date('Ymd-His');
$target = $backupRoot . '/' . $stamp;

if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
    fwrite(STDERR, "无法创建备份目录：{$target}\n");
    exit(1);
}

echo "备份开始：{$target}\n";

function run(array $command, array $env, ?string $stdoutFile = null): int
{
    $descriptors = [
        1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动命令：' . $command[0]);
    }
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if ($stdoutFile === null) {
        fclose($pipes[1]);
    }
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : '命令失败：' . implode(' ', $command));
    }
    return $code;
}

try {
    $dumpPath = $target . '/database.sql';
    $env = getenv();
    $env['MYSQL_PWD'] = (string) config('db.password');

    $command = [
        'mysqldump',
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--default-character-set=utf8mb4',
        '--host=' . config('db.host'),
        '--port=' . config('db.port'),
        '--user=' . config('db.username'),
        (string) config('db.database'),
    ];
    run($command, $env, $dumpPath);
    echo "  数据库已导出：" . filesize($dumpPath) . " 字节\n";

    $gzipEnv = getenv();
    run(['gzip', '-9', '-f', '-n', $dumpPath], $gzipEnv);
    echo "  已压缩：database.sql.gz\n";

    if ($includeUploads) {
        $uploads = BASE_PATH . '/storage/uploads';
        if (is_dir($uploads)) {
            run(['tar', '-czf', $target . '/uploads.tar.gz', '-C', BASE_PATH . '/storage', 'uploads'], getenv());
            echo "  上传目录已归档：" . filesize($target . '/uploads.tar.gz') . " 字节\n";
        } else {
            echo "  跳过上传目录（不存在）\n";
        }

        $verifications = BASE_PATH . '/storage/verifications';
        if (is_dir($verifications)) {
            run(['tar', '-czf', $target . '/verifications.tar.gz', '-C', BASE_PATH . '/storage', 'verifications'], getenv());
            echo "  核验材料已归档：" . filesize($target . '/verifications.tar.gz') . " 字节（含敏感个人信息，必须加密保管）\n";
        }
    }

    file_put_contents($target . '/manifest.json', json_encode([
        'created_at' => date(DATE_ATOM),
        'database' => config('db.database'),
        'php' => PHP_VERSION,
        'uploads' => $includeUploads,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 清理旧备份
    $entries = glob($backupRoot . '/*', GLOB_ONLYDIR) ?: [];
    sort($entries);
    $obsolete = array_slice($entries, 0, max(0, count($entries) - $keep));
    foreach ($obsolete as $dir) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
        echo "  已清理旧备份：{$dir}\n";
    }

    // 校验数据库连接仍可用（备份后自检）
    Db::value('SELECT 1');
    echo "备份完成：{$target}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '备份失败：' . $e->getMessage() . "\n");
    exit(1);
}
