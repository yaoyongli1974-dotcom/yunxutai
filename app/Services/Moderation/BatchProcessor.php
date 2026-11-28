<?php
declare(strict_types=1);

namespace App\Services\Moderation;

use App\Core\Audit;
use App\Core\Db;
use App\Models\Article;
use App\Models\Media;
use App\Models\Message;

final class BatchProcessor
{
    /**
     * 批量留言审核：仅处理 pending，其余规则与单条审核一致，带 batch 审计标记。
     *
     * @param list<int> $ids
     * @return array{done: int, skipped: int}
     */
    public static function processMessages(array $ids, string $status, int $adminId): array
    {
        /** @param array<string, mixed> $row */
        $skip = static fn (array $row): bool => (string) $row['status'] !== 'pending';
        /** @param array<string, mixed> $row */
        $apply = static function (int $id, array $row) use ($status, $adminId): void {
            Message::setStatus($id, $status, $adminId);
            Audit::log('admin.message.status', 'message', $id, [
                'status' => $status,
                'memorial_id' => (int) $row['memorial_id'],
                'batch' => true,
            ]);
        };
        return self::run('messages', $ids, $skip, $apply);
    }

    /**
     * 批量文章审核：仅处理 pending，驳回时透传统一审核说明。
     *
     * @param list<int> $ids
     * @return array{done: int, skipped: int}
     */
    public static function processArticles(array $ids, string $action, string $note): array
    {
        /** @param array<string, mixed> $row */
        $skip = static fn (array $row): bool => (string) $row['status'] !== 'pending';
        /** @param array<string, mixed> $row */
        $apply = static function (int $id, array $row) use ($action, $note): void {
            if ($action === 'approve') {
                Article::setStatus($id, 'published');
                Audit::log('admin.article.approve', 'article', $id, [
                    'memorial_id' => (int) $row['memorial_id'],
                    'batch' => true,
                ]);
            } else {
                Article::setStatus($id, 'rejected', $note !== '' ? $note : null);
                Audit::log('admin.article.reject', 'article', $id, [
                    'memorial_id' => (int) $row['memorial_id'],
                    'review_note' => $note,
                    'batch' => true,
                ]);
            }
        };
        return self::run('articles', $ids, $skip, $apply);
    }

    /**
     * 批量媒体操作：隐藏仅跳过缺失行，恢复展示额外跳过非 hidden 行。
     *
     * @param list<int> $ids
     * @return array{done: int, skipped: int}
     */
    public static function processMedia(array $ids, string $action): array
    {
        /** @param array<string, mixed> $row */
        $skip = static function (array $row) use ($action): bool {
            return $action === 'show' && (string) $row['status'] !== 'hidden';
        };
        /** @param array<string, mixed> $row */
        $apply = static function (int $id, array $row) use ($action): void {
            if ($action === 'hide') {
                Media::hide($id);
                Audit::log('admin.media.hide', 'media', $id, [
                    'memorial_id' => (int) $row['memorial_id'],
                    'batch' => true,
                ]);
            } else {
                Media::show($id);
                Audit::log('admin.media.show', 'media', $id, [
                    'memorial_id' => (int) $row['memorial_id'],
                    'batch' => true,
                ]);
            }
        };
        return self::run('media', $ids, $skip, $apply);
    }

    /**
     * 共享骨架：一次预加载替代逐行 find，按 id 顺序处理并统计成功/跳过数。
     *
     * @param list<int> $ids
     * @param callable(array<string, mixed>): bool $skip
     * @param callable(int, array<string, mixed>): void $apply
     * @return array{done: int, skipped: int}
     */
    private static function run(string $table, array $ids, callable $skip, callable $apply): array
    {
        $done = 0;
        $skipped = 0;
        $rows = $ids === [] ? [] : Db::fetchAll(
            'SELECT * FROM ' . $table . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($ids as $id) {
            $row = $byId[(int) $id] ?? null;
            if ($row === null || $skip($row)) {
                $skipped++;
                continue;
            }
            $apply($id, $row);
            $done++;
        }
        return ['done' => $done, 'skipped' => $skipped];
    }
}
