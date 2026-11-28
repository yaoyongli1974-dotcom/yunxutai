<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Notify;
use PDOException;

/** 统一通知中心：站内优先，邮件旁路；发送失败不影响核心业务。 */
final class NotificationService
{
    public const TYPES = [
        'family_invitation', 'family_member_joined', 'relationship_review', 'archive_review',
        'ai_workflow_completed', 'oral_review', 'anniversary', 'exhibition_review', 'export_ready',
    ];

    /** 站内通知必达；邮件按偏好与配置发送，失败只记录状态，不抛异常。 */
    public static function emit(
        ?int $familyId,
        int $userId,
        string $type,
        string $title,
        string $body = '',
        ?string $link = null,
        bool $allowEmail = false,
        ?string $dedupeKey = null
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException(422, '未知的通知类型');
        }
        $prefs = Db::fetch('SELECT in_app, email FROM notification_preferences WHERE user_id = ? AND type = ?', [$userId, $type]);
        $inApp = $prefs === null ? true : (bool) $prefs['in_app'];
        $email = $allowEmail && ($prefs === null ? false : (bool) $prefs['email']);
        $status = $inApp ? 'sent' : 'failed';
        $error = $inApp ? null : '用户已关闭站内通知';
        $dedupeHash = $dedupeKey !== null ? hash('sha256', $dedupeKey) : null;
        $data = [
            'user_id' => $userId,
            'family_id' => $familyId,
            'type' => $type,
            'title' => mb_substr($title, 0, 180),
            'body' => mb_substr($body, 0, 4000),
            'link' => $link !== null ? mb_substr($link, 0, 255) : null,
            'channel' => 'in_app',
            'status' => $status,
            'error_message' => $error,
        ];
        if ($dedupeHash !== null) {
            $data['dedupe_key'] = $dedupeHash;
        }
        try {
            $id = Db::insert('notifications', $data);
        } catch (PDOException $e) {
            // 并发重复通知：唯一键冲突时回查已落库记录，保持幂等语义
            if ($dedupeHash === null || ($e->errorInfo[0] ?? '') !== '23000') {
                throw $e;
            }
            $existing = Db::value('SELECT id FROM notifications WHERE dedupe_key = ? LIMIT 1', [$dedupeHash]);
            if ($existing === null) {
                throw $e; // 非重复键冲突（如外键错误）仍按原异常抛出
            }
            return (int) $existing;
        }
        if ($email) {
            self::sendEmail($userId, $type, $title, $body, $link);
        }
        return $id;
    }

    private static function sendEmail(int $userId, string $type, string $title, string $body, ?string $link): void
    {
        $email = Db::value('SELECT email FROM users WHERE id = ? LIMIT 1', [$userId]);
        if (!is_string($email) || $email === '') {
            return;
        }
        $text = $title . "\n\n" . $body . ($link !== null ? "\n\n" . \url($link) : '');
        $ok = Notify::send($email, '云叙台通知：' . mb_substr($title, 0, 80), $text);
        Db::insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 180),
            'body' => mb_substr($body, 0, 4000),
            'channel' => 'email',
            'status' => $ok ? 'sent' : 'failed',
            'error_message' => $ok ? null : '邮件发送失败，站内通知不受影响',
        ]);
        Audit::log($ok ? 'notification.email_sent' : 'notification.email_failed', 'notification', $userId, ['type' => $type]);
    }

    public static function forUser(int $userId, bool $unreadOnly = false, int $limit = 100): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(300, $limit));
        return Db::fetchAll($sql, [$userId]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function markRead(int $userId, int $id): void
    {
        $row = Db::fetch('SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if ($row === null) {
            throw new HttpException(404, '通知不存在');
        }
        Db::update('notifications', ['read_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [$id, $userId]);
    }

    public static function markAllRead(int $userId): int
    {
        return Db::update('notifications', ['read_at' => date('Y-m-d H:i:s')], 'user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function preferences(int $userId): array
    {
        $rows = Db::fetchAll('SELECT type, in_app, email FROM notification_preferences WHERE user_id = ?', [$userId]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['type']] = ['in_app' => (bool) $row['in_app'], 'email' => (bool) $row['email']];
        }
        return $out;
    }

    public static function setPreference(int $userId, string $type, bool $inApp, bool $email): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new HttpException(422, '未知的通知类型');
        }
        Db::query(
            'INSERT INTO notification_preferences (user_id, type, in_app, email)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE in_app = VALUES(in_app), email = VALUES(email)',
            [$userId, $type, $inApp ? 1 : 0, $email ? 1 : 0]
        );
    }
}
