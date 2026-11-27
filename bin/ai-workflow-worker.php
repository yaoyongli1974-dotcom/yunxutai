#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 2.0 AI 工作流 Worker（旧 AiJob 仍由 bin/ai-worker.php 处理）。
 *
 * 用法：
 *   php bin/ai-workflow-worker.php --limit=5 --max-runtime=300
 *   php bin/ai-workflow-worker.php --once=1        # 单次执行，适合 cron / 测试
 *
 * 具备：fencing token 领取、SIGTERM/SIGINT 优雅停止、SIGALRM 续租、
 * 运行时长限制、结构化日志（不记录任务输入/凭证/文件内容）。
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AiWorkflowService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['limit::', 'max-runtime::', 'once::']);
$limit = max(1, min(50, (int) ($options['limit'] ?? 5)));
$maxRuntime = max(1, (int) ($options['max-runtime'] ?? 300));
$once = array_key_exists('once', $options);
$workerId = 'w2-' . bin2hex(random_bytes(8));
$deadline = time() + $maxRuntime;
$stop = false;
$current = null; // ['id'=>int,'lock_version'=>int]
$stats = ['claimed' => 0, 'succeeded' => 0, 'failed' => 0, 'recovered' => 0, 'longest_seconds' => 0];

$logFile = BASE_PATH . '/storage/logs/ai-workflow-worker.log';
$log = static function (string $event, array $fields = []) use ($logFile, $workerId): void {
    $line = json_encode([
        'ts' => gmdate('c'), 'worker' => $workerId, 'event' => $event,
    ] + $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    fwrite(STDOUT, $line . "\n");
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop, $log): void {
        $stop = true;
        $log('signal', ['signal' => 'SIGTERM']);
    });
    pcntl_signal(SIGINT, static function () use (&$stop, $log): void {
        $stop = true;
        $log('signal', ['signal' => 'SIGINT']);
    });
    pcntl_signal(SIGALRM, static function () use (&$current, $workerId, $log): void {
        if ($current !== null && !AiWorkflowService::heartbeat($current['id'], $workerId, $current['lock_version'])) {
            $log('heartbeat_failed', ['workflow_id' => $current['id']]);
        } else {
            $log('heartbeat', ['workflow_id' => $current['id'] ?? 0]);
        }
        pcntl_alarm(60);
    });
}

$log('started', ['limit' => $limit, 'max_runtime' => $maxRuntime, 'once' => $once]);

while (!$stop && time() < $deadline) {
    try {
        $recovered = AiWorkflowService::recoverExpired();
        $stats['recovered'] += (int) ($recovered['expired'] ?? 0);
        if (($recovered['expired'] ?? 0) > 0 || ($recovered['requeued'] ?? 0) > 0) {
            $log('recovered', $recovered);
        }
    } catch (Throwable $e) {
        $log('recover_error', ['error' => mb_substr($e->getMessage(), 0, 200)]);
    }

    try {
        $claimed = AiWorkflowService::claimStrict($workerId, $limit);
    } catch (Throwable $e) {
        $log('claim_error', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        break;
    }

    if ($claimed === []) {
        if ($once) {
            break;
        }
        sleep(2);
        continue;
    }

    foreach ($claimed as $task) {
        if ($stop || time() >= $deadline) {
            break;
        }
        $current = ['id' => (int) $task['id'], 'lock_version' => (int) $task['lock_version']];
        $start = time();
        $stats['claimed']++;
        $log('claimed', ['workflow_id' => $current['id'], 'lock_version' => $current['lock_version']]);
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(60);
        }
        try {
            AiWorkflowService::process($current['id'], $workerId, $current['lock_version']);
            $stats['succeeded']++;
            $log('completed', ['workflow_id' => $current['id'], 'seconds' => time() - $start]);
        } catch (Throwable $e) {
            $stats['failed']++;
            $log('failed', [
                'workflow_id' => $current['id'],
                'seconds' => time() - $start,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            $stats['longest_seconds'] = max($stats['longest_seconds'], time() - $start);
            $current = null;
        }
    }

    if ($once) {
        break;
    }
}

$log('stopped', $stats);
exit(0);
