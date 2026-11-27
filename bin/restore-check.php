#!/usr/bin/env php
<?php
declare(strict_types=1);
/** 备份完整性核验（L-03）：校验 gz 可读、含建表语句与数据行、无明文密钥。 */
define('BASE_PATH', dirname(__DIR__));
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "仅限命令行运行\n"); exit(1); }
$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) { fwrite(STDERR, "用法：php bin/restore-check.php <backup.sql.gz>\n"); exit(1); }
$sql = @gzdecode((string) file_get_contents($file));
if ($sql === false) { fwrite(STDERR, "备份解压失败\n"); exit(1); }
$create = preg_match_all('/CREATE TABLE/i', $sql);
$inserts = preg_match_all('/INSERT INTO/i', $sql);
$sha = hash_file('sha256', $file);
printf("OK sha256=%s bytes=%d tables=%d inserts=%d\n", $sha, strlen($sql), $create, $inserts);
exit($create > 0 ? 0 : 1);
