<?php
declare(strict_types=1);

/**
 * 并发领取子进程：以给定 worker id 原子领取最多一条 AI 工作流，输出领取到的任务 id 列表。
 * 由 tests/run.php 的 E 阶段并发测试通过 proc_open 启动，使用与 bin/test.sh 相同的测试数据库环境变量。
 */
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

$workerId = (string) ($argv[1] ?? 'claim-worker');
$limit = max(1, min(50, (int) ($argv[2] ?? 1)));

$rows = \App\Services\AiWorkflowService::claimStrict($workerId, $limit);
echo json_encode(array_map(static fn (array $row): int => (int) $row['id'], $rows)), PHP_EOL;
