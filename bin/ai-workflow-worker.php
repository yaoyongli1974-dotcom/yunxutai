#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 2.0 AI 工作流 Worker（旧 ai_jobs 仍由 bin/ai-worker.php 处理）。
 * 建议每 2 分钟执行一次：
 *   php bin/ai-workflow-worker.php --limit=3 >> storage/logs/ai-workflow-worker.log 2>&1
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AiWorkflowService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['limit::']);
$limit = max(1, min(20, (int) ($options['limit'] ?? 3)));
$worker = 'worker-' . substr(sha256((string) gethostname()), 0, 12);

$recovered = AiWorkflowService::recoverExpired();
$claimed = AiWorkflowService::claim($worker, $limit);
if ($claimed === []) {
    echo "无待处理任务；回收 {$recovered['expired']} 个超时租约，重排 {$recovered['requeued']} 个任务。\n";
    exit(0);
}

$done = 0;
$failed = 0;
foreach ($claimed as $id) {
    try {
        AiWorkflowService::process($id, $worker);
        $done++;
        echo "任务 #{$id} 完成\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, '任务 #' . $id . ' 失败：' . mb_substr($e->getMessage(), 0, 200) . "\n");
    }
}
echo "本次处理：完成 {$done}，失败 {$failed}；未超过最大重试次数的任务会自动重排。\n";
exit($failed > 0 ? 1 : 0);
