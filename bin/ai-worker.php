#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AI 任务队列处理。生产环境可配置 cron，每 2 分钟执行：
 *   cd /srv/jidian && php bin/ai-worker.php --limit=3 >> storage/logs/ai-worker.log 2>&1
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Models\AiJob;
use App\Services\Ai\AiClientFactory;
use App\Services\AiStudioService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

// 与 web 端一致：后台 AI 设置覆盖 env 默认值（每次启动重载，避免静态缓存残留）。
\App\Core\Settings::reset();
\App\Core\Settings::load();

$options = getopt('', ['limit::']);
$limit = max(1, min(20, (int) ($options['limit'] ?? 3)));

if (!AiClientFactory::enabled()) {
    fwrite(STDERR, "AI 未启用，请检查 AI_ENABLED / AI_PROVIDER。\n");
    exit(1);
}

$jobs = AiJob::queued($limit);
if ($jobs === []) {
    echo "没有待处理的 AI 任务。\n";
    exit(0);
}

foreach ($jobs as $job) {
    $id = (int) $job['id'];
    echo "[{$id}] " . $job['service_slug'] . " … ";
    try {
        $result = AiStudioService::process($id);
        echo ($result['status'] ?? 'unknown') . "\n";
    } catch (Throwable $e) {
        echo "失败：" . $e->getMessage() . "\n";
    }
}

exit(0);
