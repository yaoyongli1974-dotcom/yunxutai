<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class AiJob
{
    /** 分转人民币展示：9900 → ¥99.00。 */
    public static function yuan(int $cents): string
    {
        return '¥' . number_format($cents / 100, 2, '.', '');
    }

    public static function create(int $memorialId, int $userId, string $serviceSlug, array $input, int $costCents): int
    {
        return Db::insert('ai_jobs', [
            'memorial_id' => $memorialId,
            'user_id' => $userId,
            'service_slug' => $serviceSlug,
            'status' => 'queued',
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'cost_cents' => $costCents,
            'provider' => (string) config('ai.provider'),
        ]);
    }

    public static function find(int $id): ?array
    {
        $job = Db::fetch('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1', [$id]);
        return $job === null ? null : self::hydrate($job);
    }

    public static function forMemorial(int $memorialId, int $limit = 20): array
    {
        $jobs = Db::fetchAll(
            'SELECT * FROM ai_jobs WHERE memorial_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            [$memorialId]
        );
        return array_map([self::class, 'hydrate'], $jobs);
    }

    public static function paginate(?string $status, int $limit, int $offset): array
    {
        $where = '1 = 1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND j.status = ?';
            $params[] = $status;
        }
        $jobs = Db::fetchAll(
            "SELECT j.*, m.slug, m.title AS memorial_title, u.display_name AS user_name
             FROM ai_jobs j
             JOIN memorials m ON m.id = j.memorial_id
             JOIN users u ON u.id = j.user_id
             WHERE {$where}
             ORDER BY FIELD(j.status, 'failed', 'queued', 'processing', 'completed'), j.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return array_map([self::class, 'hydrate'], $jobs);
    }

    public static function count(?string $status = null): int
    {
        if ($status === null || $status === '') {
            return (int) Db::value('SELECT COUNT(*) FROM ai_jobs');
        }
        return (int) Db::value('SELECT COUNT(*) FROM ai_jobs WHERE status = ?', [$status]);
    }

    public static function queued(int $limit = 5): array
    {
        return Db::fetchAll(
            "SELECT * FROM ai_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT " . max(1, $limit)
        );
    }

    public static function markProcessing(int $id): bool
    {
        return Db::query(
            "UPDATE ai_jobs
             SET status = 'processing', started_at = ?, error_message = NULL
             WHERE id = ? AND status IN ('queued','failed')",
            [date('Y-m-d H:i:s'), $id]
        )->rowCount() === 1;
    }

    public static function markCompleted(int $id, array $output, array $links = []): void
    {
        Db::update('ai_jobs', [
            'status' => 'completed',
            'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE),
            'media_id' => $links['media_id'] ?? null,
            'article_id' => $links['article_id'] ?? null,
            'finished_at' => date('Y-m-d H:i:s'),
            'error_message' => null,
        ], 'id = ?', [$id]);
    }

    public static function markFailed(int $id, string $message): void
    {
        Db::update('ai_jobs', [
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 500),
            'finished_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);
    }

    public static function retry(int $id): void
    {
        Db::update('ai_jobs', [
            'status' => 'queued',
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ], 'id = ?', [$id]);
    }

    public static function hydrate(array $job): array
    {
        $job['input'] = $job['input_json'] ? json_decode((string) $job['input_json'], true) : [];
        $job['output'] = $job['output_json'] ? json_decode((string) $job['output_json'], true) : [];
        if (!is_array($job['input'])) {
            $job['input'] = [];
        }
        if (!is_array($job['output'])) {
            $job['output'] = [];
        }
        return $job;
    }
}
