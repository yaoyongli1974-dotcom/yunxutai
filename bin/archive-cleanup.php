#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 档案删除清理 Worker（R-04）：先标记待清理、由独立幂等任务异步清理物理文件。
 *
 * 用法：
 *   php bin/archive-cleanup.php --once --limit=20 --max-attempts=3   # cron 单次批次（推荐）
 *   php bin/archive-cleanup.php --limit=50                           # 常驻轮询
 *
 * 参数：--limit=N（默认 20，最大 100）、--once（处理一批后退出）、--max-attempts=N（默认 3）。
 * 不注册调度器：由部署侧 cron 调用即可。仅处理 archives.status='deleted' 的队列项；
 * 共享存储文件只关闭队列项、不删除物理文件；Storage::delete 容忍文件缺失，重复执行安全。
 */
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Audit;
use App\Core\Db;
use App\Core\Storage;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['limit::', 'once::', 'max-attempts::']);
$limit = max(1, min(100, (int) ($options['limit'] ?? 20)));
$maxAttempts = max(1, (int) ($options['max-attempts'] ?? 3));
$once = array_key_exists('once', $options);
$stats = ['claimed' => 0, 'cleaned' => 0, 'shared' => 0, 'skipped' => 0, 'failed' => 0];

/** 原子领取：FOR UPDATE SKIP LOCKED + 条件更新，attempts 在领取时递增一次。 */
$claim = static function (int $batchSize) use ($maxAttempts): array {
    return Db::transaction(static function () use ($batchSize, $maxAttempts): array {
        $rows = Db::fetchAll(
            "SELECT id, archive_id, file_id, storage_key, attempts
             FROM archive_cleanup_queue
             WHERE status = 'pending' AND attempts < {$maxAttempts}
             ORDER BY id ASC LIMIT {$batchSize}
             FOR UPDATE SKIP LOCKED"
        );
        $claimed = [];
        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            $updated = Db::update(
                'archive_cleanup_queue',
                ['status' => 'processing', 'attempts' => $attempts, 'last_error' => null],
                "id = ? AND status = 'pending'",
                [(int) $row['id']]
            );
            if ($updated !== 1) {
                continue;
            }
            $claimed[] = [
                'id' => (int) $row['id'],
                'archive_id' => (int) $row['archive_id'],
                'file_id' => $row['file_id'] === null ? null : (int) $row['file_id'],
                'storage_key' => (string) $row['storage_key'],
                'attempts' => $attempts,
            ];
        }
        return $claimed;
    });
};

/** 单项清理：缺文件视为成功；共享文件与非删除档案只关闭队列项。 */
$process = static function (array $row) use ($maxAttempts, &$stats): void {
    $queueId = (int) $row['id'];
    $archiveId = (int) $row['archive_id'];
    $fileId = $row['file_id'];
    $storageKey = (string) $row['storage_key'];

    try {
        $status = Db::value('SELECT status FROM archives WHERE id = ? LIMIT 1', [$archiveId]);
        if ((string) $status !== 'deleted') {
            Db::update('archive_cleanup_queue', ['status' => 'done', 'last_error' => null], 'id = ?', [$queueId]);
            Audit::log('archive.file_cleanup', 'archive', $archiveId, [
                'queue_id' => $queueId, 'file_id' => $fileId, 'result' => 'skipped', 'reason' => 'archive_not_deleted',
            ]);
            $stats['skipped']++;
            echo "  跳过（档案未删除）：queue={$queueId} archive={$archiveId}\n";
            return;
        }

        // 共享存储键：只关闭队列项，绝不删除仍被其他档案引用的物理文件。
        $shared = Db::fetch(
            'SELECT id FROM archive_files WHERE storage_key = ? AND id <> ? LIMIT 1',
            [$storageKey, $fileId ?? 0]
        );
        if ($shared !== null) {
            Db::update('archive_cleanup_queue', ['status' => 'done', 'last_error' => null], 'id = ?', [$queueId]);
            Audit::log('archive.file_cleanup', 'archive', $archiveId, [
                'queue_id' => $queueId, 'file_id' => $fileId, 'result' => 'shared', 'other_file_id' => (int) $shared['id'],
            ]);
            $stats['shared']++;
            echo "  共享文件保留：queue={$queueId} archive={$archiveId}\n";
            return;
        }

        // 文件系统与 MySQL 无法共享事务：先删物理文件（幂等、缺失即成功），再收尾数据库记录。
        // 原始副本与处理后文件同生命周期，必须一起清理，避免残留可恢复的隐私数据。
        $originalKey = null;
        if ($fileId !== null) {
            $originalKey = Db::value('SELECT original_storage_key FROM archive_files WHERE id = ? LIMIT 1', [$fileId]);
        }
        Storage::delete($storageKey);
        if ($originalKey !== null && $originalKey !== '') {
            Storage::delete((string) $originalKey);
        }
        if ($fileId !== null) {
            Db::query('DELETE FROM archive_files WHERE id = ? AND archive_id = ?', [$fileId, $archiveId]);
        }
        Db::update('archive_cleanup_queue', ['status' => 'done', 'last_error' => null], 'id = ?', [$queueId]);
        Audit::log('archive.file_cleanup', 'archive', $archiveId, [
            'queue_id' => $queueId, 'file_id' => $fileId, 'result' => 'cleaned',
            'original_removed' => $originalKey !== null && $originalKey !== '',
        ]);
        $stats['cleaned']++;
        echo "  已清理：queue={$queueId} archive={$archiveId}\n";
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 500);
        // attempts 已在领取时原子递增；此处不重复计数，仅决定重试或终止。
        $terminal = $row['attempts'] >= $maxAttempts;
        Db::update('archive_cleanup_queue', [
            'status' => $terminal ? 'failed' : 'pending',
            'last_error' => $error,
        ], 'id = ?', [$queueId]);
        Audit::log('archive.file_cleanup', 'archive', $archiveId, [
            'queue_id' => $queueId, 'file_id' => $fileId,
            'result' => $terminal ? 'failed' : 'retry', 'attempts' => $row['attempts'],
            'error' => mb_substr($error, 0, 200),
        ]);
        $stats['failed']++;
        echo "  清理失败：queue={$queueId} archive={$archiveId} attempts={$row['attempts']}\n";
    }
};

try {
    Db::value('SELECT 1'); // 启动自检：数据库不可用时以退出码 1 报错。
    echo "archive-cleanup 启动：limit={$limit} max_attempts={$maxAttempts} once=" . ($once ? '1' : '0') . "\n";
    while (true) {
        $claimed = $claim($limit);
        foreach ($claimed as $row) {
            $stats['claimed']++;
            $process($row);
        }
        if ($once) {
            break;
        }
        if ($claimed === []) {
            sleep(2);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'archive-cleanup 致命错误：' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf(
    "完成：领取 %d，清理 %d，共享保留 %d，跳过 %d，失败 %d\n",
    $stats['claimed'], $stats['cleaned'], $stats['shared'], $stats['skipped'], $stats['failed']
);
exit(0);
