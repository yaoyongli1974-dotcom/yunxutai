#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * 备份完整性核验（L-03）：按 manifest 校验各产物 sha256/bytes，解压 gz 确认含建表语句与数据行。
 * 支持 AES-256-CBC 加密备份（manifest.encryption + key_fingerprint），密钥仅从环境变量 BACKUP_ENCRYPTION_KEY 读取。
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
  加密备份：manifest 标注 encryption=aes-256-cbc 时，需设置环境变量
  BACKUP_ENCRYPTION_KEY；先校验密文 sha256/bytes，再解密并按明文校验。
  密钥不写入备份目录，错误密钥会导致校验失败。
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

$tempFiles = [];
$resolved = [];
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
            $encrypted = ($manifest['encryption'] ?? null) === 'aes-256-cbc';
            $encKey = null;
            if ($encrypted) {
                $keyText = (string) (getenv('BACKUP_ENCRYPTION_KEY') ?: '');
                if ($keyText === '') {
                    $reasons[] = '备份已加密但未提供 BACKUP_ENCRYPTION_KEY';
                } else {
                    $encKey = hash('sha256', $keyText, true);
                    $fingerprint = substr(hash('sha256', $keyText), 0, 16);
                    if (!hash_equals((string) ($manifest['key_fingerprint'] ?? ''), $fingerprint)) {
                        $reasons[] = 'BACKUP_ENCRYPTION_KEY 指纹不匹配（密钥错误）';
                        $encKey = null;
                    }
                }
            }
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
                    $isEncrypted = $encrypted && is_array($meta) && ($meta['encrypted'] ?? false) === true;
                    $path = $target . '/' . $name . ($isEncrypted ? '.enc' : '');
                    if (!is_file($path)) {
                        $reasons[] = "缺少产物 {$name}" . ($isEncrypted ? '.enc' : '');
                        continue;
                    }
                    $cipherSha = (string) hash_file('sha256', $path);
                    $cipherBytes = (int) filesize($path);
                    if ($isEncrypted) {
                        $expectedCipherSha = is_string($meta['enc_sha256'] ?? null) ? $meta['enc_sha256'] : null;
                        $expectedCipherBytes = is_numeric($meta['enc_bytes'] ?? null) ? (int) $meta['enc_bytes'] : null;
                        if ($expectedCipherSha === null || !hash_equals(strtolower($expectedCipherSha), strtolower($cipherSha))) {
                            $reasons[] = "{$name}.enc 密文 sha256 不匹配";
                            continue;
                        }
                        if ($expectedCipherBytes !== $cipherBytes) {
                            $reasons[] = "{$name}.enc 密文 bytes 不匹配";
                            continue;
                        }
                        if ($encKey === null) {
                            $reasons[] = "{$name} 无法解密（密钥缺失或错误）";
                            continue;
                        }
                        $iv = base64_decode((string) ($meta['iv'] ?? ''), true);
                        if ($iv === false || strlen($iv) !== 16) {
                            $reasons[] = "{$name} IV 无效";
                            continue;
                        }
                        $plain = openssl_decrypt((string) file_get_contents($path), 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
                        if ($plain === false) {
                            $reasons[] = "{$name} 解密失败（密钥错误或密文损坏）";
                            continue;
                        }
                        $tmp = @tempnam(sys_get_temp_dir(), 'yxt-restore-');
                        if ($tmp === false || file_put_contents($tmp, $plain) === false) {
                            $reasons[] = "{$name} 无法写入解密临时文件";
                            continue;
                        }
                        $tempFiles[] = $tmp;
                        $resolved[$nameText] = $tmp;
                        $actualSha = hash('sha256', $plain);
                        $actualBytes = strlen($plain);
                    } else {
                        $actualSha = $cipherSha;
                        $actualBytes = $cipherBytes;
                        $resolved[$nameText] = $path;
                    }
                    $expectedSha = is_array($meta) && is_string($meta['sha256'] ?? null) ? $meta['sha256'] : null;
                    $expectedBytes = is_array($meta) && is_numeric($meta['bytes'] ?? null) ? (int) $meta['bytes'] : null;
                    $shaMatch = $expectedSha !== null && hash_equals(strtolower($expectedSha), strtolower($actualSha));
                    $bytesMatch = $expectedBytes !== null && $expectedBytes === $actualBytes;
                    printf("  %s sha256=%s bytes=%d %s%s\n", $nameText, $actualSha, $actualBytes, ($shaMatch && $bytesMatch) ? 'OK' : 'FAILED', $isEncrypted ? ' (decrypted)' : '');
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
    $dbPath = $resolved['database.sql.gz'] ?? ($target . '/database.sql.gz');
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

foreach ($tempFiles as $tmp) {
    @unlink($tmp);
}
if ($reasons === []) {
    printf("OK sha256=%s bytes=%d tables=%d inserts=%d\n", $sha, $bytes, $tables, $inserts);
    exit(0);
}
printf("FAILED sha256=%s bytes=%d tables=%d inserts=%d reasons=%s\n", $sha, $bytes, $tables, $inserts, implode('; ', $reasons));
exit(1);
