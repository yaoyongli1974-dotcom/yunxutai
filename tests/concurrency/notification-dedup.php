<?php
declare(strict_types=1);

/**
 * 并发通知去重子进程：以给定去重键发送 AI 工作流完成通知，输出通知 id。
 * 由 tests/run.php 的 Phase 6 并发测试通过 proc_open 启动多个副本，
 * 使用与 bin/test.sh 相同的测试数据库环境变量（DB_DATABASE 由 bin/test.sh 导出，bootstrap 读取 .env）。
 *
 * argv: [dedupeKey] [userId=1]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

$dedupeKey = (string) ($argv[1] ?? '');
if ($dedupeKey === '') {
    fwrite(STDERR, "missing dedupeKey\n");
    exit(1);
}
$userId = (int) ($argv[2] ?? 1);

try {
    $id = \App\Services\NotificationService::emit(
        null,
        (int) $userId,
        'ai_workflow_completed',
        '并发通知',
        'body',
        '/x',
        false,
        $dedupeKey
    );
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode(['id' => (int) $id]) . PHP_EOL;
exit(0);
