<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * AI 付费订单（订单审核制）：充值开关开启时，AI 任务先建订单，
 * 平台人工确认收款/计价后 confirm 自动创建真正的 ai_job。
 */
final class AiOrder
{
    public static function create(int $memorialId, int $userId, array $service, array $input): int
    {
        return Db::insert('ai_orders', [
            'memorial_id' => $memorialId,
            'user_id' => $userId,
            'service_slug' => (string) $service['slug'],
            'service_name' => (string) $service['name'],
            'price_cents' => (int) $service['price_cents'],
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
        ]);
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM ai_orders WHERE id = ? LIMIT 1', [$id]);
    }

    public static function forMemorial(int $memorialId, int $limit = 10): array
    {
        return Db::fetchAll(
            'SELECT * FROM ai_orders WHERE memorial_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)),
            [$memorialId]
        );
    }

    public static function recent(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT o.*, u.email AS user_email, u.display_name AS user_name,
                       m.slug AS memorial_slug, d.full_name AS memorial_name
                FROM ai_orders o
                LEFT JOIN users u ON u.id = o.user_id
                LEFT JOIN memorials m ON m.id = o.memorial_id
                LEFT JOIN deceased d ON d.id = m.deceased_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE o.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $limit;
        return Db::fetchAll($sql, $params);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Db::update('ai_orders', $data, 'id = ?', [$id]);
    }
}
