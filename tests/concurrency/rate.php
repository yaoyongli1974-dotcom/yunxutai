<?php
declare(strict_types=1);

/** 并发限流子进程：允许输出 1，拒绝输出 0。 */
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

$key = (string) ($argv[1] ?? '');
if ($key === '') {
    fwrite(STDERR, "missing key\n");
    exit(2);
}
echo \App\Core\RateLimiter::hit($key, 10, 60) ? "1\n" : "0\n";
