<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class AiService
{
    public const DEFAULTS = [
        [
            'slug' => 'photo_restore',
            'name' => '老照片修复',
            'description' => '对破损、模糊或偏色的照片做增强修复，生成一份新的照片副本，原图不会被覆盖。',
            'unit' => '张',
            'price_cents' => 9900,
            'price_note' => '占位价，待运营确认；内测期不实际扣费。',
            'sort_order' => 1,
        ],
        [
            'slug' => 'memorial_video',
            'name' => '纪念短片',
            'description' => '从纪念馆照片生成安静的纪念短片，并附上 AI 整理的分镜文字；可在确认后用于追思。',
            'unit' => '支',
            'price_cents' => 39900,
            'price_note' => '占位价，按素材数量与时长确认；内测期不实际扣费。',
            'sort_order' => 2,
        ],
        [
            'slug' => 'life_article',
            'name' => '生平文章整理',
            'description' => '依据已填写的生平、时间轴与文章，整理一篇生平草稿；发布前必须由家属核对事实。',
            'unit' => '篇',
            'price_cents' => 19900,
            'price_note' => '占位价，按资料完整度确认；内测期不实际扣费。',
            'sort_order' => 3,
        ],
        [
            'slug' => 'family_archive',
            'name' => '家族数字档案',
            'description' => '把纪念馆资料整理成一份可离线保存的 HTML 档案，包含生平、时间轴、文章与照片缩略图。',
            'unit' => '份',
            'price_cents' => 99900,
            'price_note' => '占位价，按家族资料量确认；内测期不实际扣费。',
            'sort_order' => 4,
        ],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $service) {
            if (Db::value('SELECT id FROM ai_services WHERE slug = ?', [$service['slug']]) === null) {
                Db::insert('ai_services', $service + ['enabled' => 1]);
            }
        }
    }

    public static function all(bool $onlyEnabled = false): array
    {
        $where = $onlyEnabled ? 'WHERE enabled = 1' : '';
        return Db::fetchAll("SELECT * FROM ai_services {$where} ORDER BY sort_order ASC, id ASC");
    }

    public static function find(int $id): ?array
    {
        return Db::fetch('SELECT * FROM ai_services WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Db::fetch('SELECT * FROM ai_services WHERE slug = ? LIMIT 1', [$slug]);
    }

    public static function update(int $id, array $data): void
    {
        Db::update('ai_services', [
            'price_cents' => $data['price_cents'],
            'price_note' => $data['price_note'] ?: null,
            'enabled' => $data['enabled'] ? 1 : 0,
        ], 'id = ?', [$id]);
    }
}
