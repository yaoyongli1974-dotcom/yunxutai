<?php
declare(strict_types=1);

/**
 * 并发订单幂等子进程：以给定幂等键创建 AI 订单，输出订单 id。
 * 由 tests/run.php 的 Phase 6 并发测试通过 proc_open 启动多个副本，
 * 使用与 bin/test.sh 相同的测试数据库环境变量（DB_DATABASE 由 bin/test.sh 导出，bootstrap 读取 .env）。
 *
 * argv: [key] [memorialId=1] [userId=1]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

$key = (string) ($argv[1] ?? '');
if ($key === '') {
    fwrite(STDERR, "missing key\n");
    exit(1);
}

try {
    $id = \App\Models\AiOrder::create(
        (int) ($argv[2] ?? 1),
        (int) ($argv[3] ?? 1),
        ['slug' => 'concurrency', 'name' => '并发', 'price_cents' => 100],
        ['notes' => 'x'],
        $key
    );
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode(['id' => (int) $id]) . PHP_EOL;
exit(0);
