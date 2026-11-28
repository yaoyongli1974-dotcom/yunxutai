<?php
declare(strict_types=1);

namespace App\Services\Moderation;

use App\Core\Db;

final class ModerationQueries
{
    /**
     * 审核台媒体列表：隐藏媒体 50 条、最近媒体 20 条。
     *
     * @return array{hiddenMedia: array, recentMedia: array}
     */
    public static function dashboardData(): array
    {
        $hiddenMedia = Db::fetchAll(
            "SELECT md.*, m.slug, d.full_name
             FROM media md
             JOIN memorials m ON m.id = md.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE md.status = 'hidden'
             ORDER BY md.created_at DESC, md.id DESC LIMIT 50"
        );

        $recentMedia = Db::fetchAll(
            "SELECT md.*, m.slug, d.full_name
             FROM media md
             JOIN memorials m ON m.id = md.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE md.status = 'active'
             ORDER BY md.created_at DESC, md.id DESC LIMIT 20"
        );

        return ['hiddenMedia' => $hiddenMedia, 'recentMedia' => $recentMedia];
    }
}
