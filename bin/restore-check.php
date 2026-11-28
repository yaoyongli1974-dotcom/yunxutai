#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * 备份完整性核验（L-03）：按 manifest 校验各产物 sha256/bytes，解压 gz 确认含建表语句与数据行。
 * 只读检查，不导入数据库。
 * 用法：php bin/restore-check.php <备份目录|backup.sql.gz>
 */
define('BASE_PATH', dirname(__DIR__));
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "仅限命令行运行\n"); exit(1); }

// PHP 8.5 / 禁用 register_argc_argv 的环境下 $argv/$argc 可能未定义，统一归一化。
$argv = $argv ?? [];
$argc = count($argv);

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    $help = <<<'TXT'
用法：php bin/restore-check.php <备份目录|backup.sql.gz>

  备份目录（推荐）：读取 manifest.json，逐一核对 database.sql.gz /
  uploads.tar.gz / verifications.tar.gz 是否存在且 sha256、bytes 与
  manifest 一致，再解压 database.sql.gz 统计 CREATE TABLE / INSERT INTO。
  单文件 gz（旧版备份）：无 manifest 校验和可比，只检查 gzip 可读、
  含建表语句与数据行。

退出码：0 = 完整性通过（校验和一致、tables>0、inserts>0）；1 = 失败。
说明：本命令只做备份完整性检查，不导入数据库，也不能证明完整恢复成功。
TXT;
    fwrite(STDOUT, $help . "\n");
    exit(0);
}

if ($argc !== 2) {
    fwrite(STDERR, "用法：php bin/restore-check.php <备份目录|backup.sql.gz>（--help 查看说明）\n");
    exit(1);
}
$target = $argv[1];
$isDir = is_dir($target);
if (!$isDir && !is_file($target)) {
    fwrite(STDERR, "FAILED 目标不存在或不是备份目录/gz 文件：{$target}\n");
    exit(1);
}

$reasons = [];
$sha = 'n/a';
$bytes = 0;

if ($isDir) {
    $manifestPath = $target . '/manifest.json';
    if (!is_file($manifestPath)) {
        $reasons[] = '缺少 manifest.json';
    } else {
        $raw = @file_get_contents($manifestPath);
        $manifest = $raw === false ? null : json_decode($raw, true);
        if (!is_array($manifest)) {
            $reasons[] = 'manifest.json 无法解析';
        } else {
            $files = $manifest['files'] ?? null;
            if (!is_array($files) || !array_key_exists('database.sql.gz', $files)) {
                $reasons[] = 'manifest.json 缺少 files.database.sql.gz 校验信息（旧版备份无可核验校验和）';
            } else {
                foreach ($files as $name => $meta) {
                    $nameText = (string) $name;
                    if ($nameText === '' || str_starts_with($nameText, '.') || preg_match('/[^A-Za-z0-9._-]/', $nameText)) {
                        $reasons[] = 'manifest 中产物名非法：' . $nameText;
                        continue;
                    }
                    $path = $target . '/' . $name;
                    if (!is_file($path)) {
                        $reasons[] = "缺少产物 {$name}";
                        continue;
                    }
                    $actualSha = (string) hash_file('sha256', $path);
                    $actualBytes = (int) filesize($path);
                    $expectedSha = is_array($meta) && is_string($meta['sha256'] ?? null) ? $meta['sha256'] : null;
                    $expectedBytes = is_array($meta) && is_numeric($meta['bytes'] ?? null) ? (int) $meta['bytes'] : null;
                    $shaMatch = $expectedSha !== null && hash_equals(strtolower($expectedSha), strtolower($actualSha));
                    $bytesMatch = $expectedBytes !== null && $expectedBytes === $actualBytes;
                    printf("  %s sha256=%s bytes=%d %s\n", $name, $actualSha, $actualBytes, ($shaMatch && $bytesMatch) ? 'OK' : 'FAILED');
                    if (!$shaMatch) {
                        $reasons[] = $expectedSha === null ? "{$name} 缺少 sha256 基准" : "{$name} sha256 不匹配";
                    }
                    if (!$bytesMatch) {
                        $reasons[] = $expectedBytes === null
                            ? "{$name} 缺少 bytes 基准"
                            : "{$name} bytes 不匹配（manifest={$expectedBytes}, 实际={$actualBytes}）";
                    }
                    if ($name === 'database.sql.gz') {
                        $sha = $actualSha;
                        $bytes = $actualBytes;
                    }
                }
            }
        }
    }
    $dbPath = $target . '/database.sql.gz';
    if (!is_file($dbPath)) {
        $missing = '缺少产物 database.sql.gz';
        if (!in_array($missing, $reasons, true)) {
            $reasons[] = $missing;
        }
    }
} else {
    $dbPath = $target;
    $sha = (string) hash_file('sha256', $dbPath);
    $bytes = (int) filesize($dbPath);
    printf("  %s sha256=%s bytes=%d （legacy 模式：无 manifest 校验和可比）\n", basename($dbPath), $sha, $bytes);
}

$tables = 0;
$inserts = 0;
if (is_file($dbPath)) {
    if ($sha === 'n/a') {
        $sha = (string) hash_file('sha256', $dbPath);
        $bytes = (int) filesize($dbPath);
    }
    $raw = @file_get_contents($dbPath);
    $sql = $raw === false ? false : @gzdecode($raw);
    if ($sql === false) {
        $reasons[] = 'database.sql.gz 解压失败或不是有效 gzip';
    } else {
        $tables = (int) preg_match_all('/CREATE TABLE/i', $sql);
        $inserts = (int) preg_match_all('/INSERT INTO/i', $sql);
        if ($tables <= 0) {
            $reasons[] = '未发现 CREATE TABLE';
        }
        if ($inserts <= 0) {
            $reasons[] = '未发现 INSERT INTO（空库 dump 不算可用备份）';
        }
    }
}

if ($reasons === []) {
    printf("OK sha256=%s bytes=%d tables=%d inserts=%d\n", $sha, $bytes, $tables, $inserts);
    exit(0);
}
printf("FAILED sha256=%s bytes=%d tables=%d inserts=%d reasons=%s\n", $sha, $bytes, $tables, $inserts, implode('; ', $reasons));
exit(1);
