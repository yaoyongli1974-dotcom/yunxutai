#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 备份数据库与上传目录。
 * 用法：php bin/backup.php [--keep=14] [--no-uploads]
 * 产物：storage/backups/YYYYmmdd-HHMMSS/{database.sql.gz,uploads.tar.gz,verifications.tar.gz,manifest.json}
 * 配置 BACKUP_ENCRYPTION_KEY 后产物落盘为 *.enc（AES-256-CBC），明文随即删除；
 * manifest.json 的 files 段记录明文与密文 sha256/bytes，可用 bin/restore-check.php 核验。
 * 异地存储策略见 docs/deployment.md，本脚本不自动上传。
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

    // 所有产物落盘后再计算校验和并写入 manifest。
    // 配置 BACKUP_ENCRYPTION_KEY 时对产物做 AES-256-CBC 加密；密钥不写入 manifest，仅存指纹用于校验。
    $encryptionKey = (string) (getenv('BACKUP_ENCRYPTION_KEY') ?: '');
    if ($encryptionKey !== '' && strlen($encryptionKey) < 16) {
        throw new RuntimeException('BACKUP_ENCRYPTION_KEY 至少需要 16 个字符');
    }
    $encKey = $encryptionKey !== '' ? hash('sha256', $encryptionKey, true) : null;
    $files = [];
    foreach (['database.sql.gz', 'uploads.tar.gz', 'verifications.tar.gz'] as $artifact) {
        $path = $target . '/' . $artifact;
        if (!is_file($path)) {
            continue;
        }
        $plaintext = (string) file_get_contents($path);
        $meta = [
            'sha256' => hash('sha256', $plaintext),
            'bytes' => strlen($plaintext),
        ];
        if ($encKey !== null) {
            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext === false) {
                throw new RuntimeException('备份加密失败：' . $artifact);
            }
            file_put_contents($path . '.enc', $ciphertext);
            @chmod($path . '.enc', 0600);
            @unlink($path);
            $meta['encrypted'] = true;
            $meta['iv'] = base64_encode($iv);
            $meta['enc_sha256'] = hash('sha256', $ciphertext);
            $meta['enc_bytes'] = strlen($ciphertext);
        }
        $files[$artifact] = $meta;
    }

    file_put_contents($target . '/manifest.json', json_encode([
        'created_at' => date(DATE_ATOM),
        'database' => config('db.database'),
        'php' => PHP_VERSION,
        'uploads' => $includeUploads,
        'encryption' => $encKey !== null ? 'aes-256-cbc' : null,
        'key_fingerprint' => $encKey !== null ? substr(hash('sha256', $encryptionKey), 0, 16) : null,
        'files' => $files,
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
