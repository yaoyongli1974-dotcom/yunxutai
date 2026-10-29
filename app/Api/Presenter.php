<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\MediaSign;

/**
 * 输出整形：统一字段命名，并确保任何敏感字段（邮箱、磁盘路径）不外泄。
 */
final class Presenter
{
    /**
     * 邮箱打码：a***@e.com（本地部分只保留首字符）。
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '') {
            return '***@' . $domain;
        }
        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    public static function user(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'nickname' => (string) $user['display_name'],
            'email' => self::maskEmail($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? 'user'),
        ];
    }

    public static function membership(array $row): array
    {
        return [
            'memorial_id' => (int) $row['memorial_id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['title'],
            'role' => (string) $row['role'],
        ];
    }

    public static function memorialSummary(array $memorial): array
    {
        return [
            'id' => (int) $memorial['id'],
            'slug' => (string) $memorial['slug'],
            'name' => (string) $memorial['title'],
            'title' => (string) $memorial['title'],
            'full_name' => (string) ($memorial['full_name'] ?? ''),
            'summary' => (string) ($memorial['summary'] ?? ''),
            'visibility' => (string) $memorial['visibility'],
            'status' => (string) $memorial['status'],
            'verification_status' => (string) ($memorial['verification_status'] ?? 'none'),
            'allow_guestbook' => (int) ($memorial['allow_guestbook'] ?? 0) === 1,
            'birth_date' => $memorial['birth_date'] ?? null,
            'death_date' => $memorial['death_date'] ?? null,
            'cover_media_id' => isset($memorial['cover_media_id']) ? (int) $memorial['cover_media_id'] : null,
            'published_at' => $memorial['published_at'] ?? null,
            'updated_at' => $memorial['updated_at'] ?? null,
        ];
    }

    public static function profile(array $memorial): array
    {
        return [
            'full_name' => (string) ($memorial['full_name'] ?? ''),
            'gender' => (string) ($memorial['gender'] ?? 'unknown'),
            'birth_date' => $memorial['birth_date'] ?? null,
            'death_date' => $memorial['death_date'] ?? null,
            'birth_place' => $memorial['birth_place'] ?? null,
            'death_place' => $memorial['death_place'] ?? null,
            'bio' => (string) ($memorial['bio'] ?? ''),
        ];
    }

    public static function life(array $event): array
    {
        return [
            'id' => (int) $event['id'],
            'event_date' => $event['event_date'] ?? null,
            'year_label' => $event['year_label'] ?? null,
            'title' => (string) $event['title'],
            'description' => (string) ($event['description'] ?? ''),
            'location' => $event['location'] ?? null,
        ];
    }

    public static function media(array $media): array
    {
        return [
            'id' => (int) $media['id'],
            'kind' => (string) $media['kind'],
            'caption' => $media['caption'] ?? null,
            'mime' => (string) $media['mime'],
            'visibility' => (string) $media['visibility'],
            'created_at' => $media['created_at'] ?? null,
            // 小程序无法携带网页 session，统一走签名直链；未就绪时降级为 null。
            'media_url' => class_exists(MediaSign::class) ? MediaSign::url((int) $media['id']) : null,
        ];
    }

    public static function articleListItem(array $article): array
    {
        return [
            'id' => (int) $article['id'],
            'title' => (string) $article['title'],
            'published_at' => $article['published_at'] ?? null,
            'updated_at' => $article['updated_at'] ?? null,
        ];
    }

    /**
     * 网页端是「先转义再分段」，此处保持同一渲染口径，避免 XSS。
     */
    public static function contentHtml(string $content): string
    {
        $html = '';
        foreach (preg_split('/\n{2,}/u', trim($content)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }
        return $html;
    }
}
