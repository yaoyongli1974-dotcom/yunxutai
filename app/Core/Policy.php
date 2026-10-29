<?php
declare(strict_types=1);

namespace App\Core;

final class Policy
{
    /** @var array<string, string|null> */
    private static array $roles = [];

    public static function memberRole(int $memorialId, ?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        $key = $memorialId . ':' . $userId;
        if (array_key_exists($key, self::$roles)) {
            return self::$roles[$key];
        }
        $role = Db::value(
            'SELECT role FROM memorial_members WHERE memorial_id = ? AND user_id = ? AND status = ? LIMIT 1',
            [$memorialId, $userId, 'active']
        );
        return self::$roles[$key] = ($role !== null ? (string) $role : null);
    }

    public static function role(array $memorial, ?array $user): ?string
    {
        if (!$user) {
            return null;
        }
        if (($user['role'] ?? '') === 'admin') {
            return 'admin';
        }
        return self::memberRole((int) $memorial['id'], (int) $user['id']);
    }

    public static function canView(array $memorial, ?array $user): bool
    {
        $role = self::role($memorial, $user);
        if ($role !== null) {
            return true;
        }
        if (($memorial['status'] ?? '') !== 'active') {
            return false;
        }
        // 未完成身份材料核验的纪念馆只能以私密方式存在，即使误将 visibility 改为公开也不外显。
        if (($memorial['verification_status'] ?? 'none') !== 'verified') {
            return false;
        }
        return in_array($memorial['visibility'] ?? '', ['public', 'unlisted'], true);
    }

    public static function canEdit(array $memorial, ?array $user): bool
    {
        $role = self::role($memorial, $user);
        if (in_array($role, ['admin', 'owner', 'editor'], true)) {
            return true;
        }
        return false;
    }

    public static function canManage(array $memorial, ?array $user): bool
    {
        return in_array(self::role($memorial, $user), ['admin', 'owner'], true);
    }

    public static function canModerate(array $memorial, ?array $user): bool
    {
        return in_array(self::role($memorial, $user), ['admin', 'owner', 'editor'], true);
    }

    public static function canPost(array $memorial, ?array $user): bool
    {
        if (!$user || ($memorial['status'] ?? '') !== 'active') {
            return false;
        }
        if (!self::canView($memorial, $user)) {
            return false;
        }
        if (($memorial['visibility'] ?? '') === 'private') {
            return self::memberRole((int) $memorial['id'], (int) $user['id']) !== null;
        }
        return true;
    }

    public static function canViewMedia(array $media, array $memorial, ?array $user): bool
    {
        if (!self::canView($memorial, $user)) {
            return false;
        }
        if (($media['visibility'] ?? 'visitors') === 'members') {
            return self::role($memorial, $user) !== null;
        }
        return true;
    }

    public static function isIndexable(array $memorial): bool
    {
        return ($memorial['status'] ?? '') === 'active'
            && ($memorial['verification_status'] ?? 'none') === 'verified'
            && ($memorial['visibility'] ?? '') === 'public'
            && (int) ($memorial['allow_search_index'] ?? 0) === 1;
    }

    public static function clearCache(): void
    {
        self::$roles = [];
    }
}
