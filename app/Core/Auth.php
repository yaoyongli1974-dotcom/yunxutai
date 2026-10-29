<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = $_SESSION['uid'] ?? null;
        if (!is_numeric($id)) {
            return null;
        }

        $user = Db::fetch('SELECT * FROM users WHERE id = ? AND status = ? LIMIT 1', [(int) $id, 'active']);
        self::$user = $user ?: null;

        if ($user === null) {
            unset($_SESSION['uid']);
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    public static function attempt(string $email, string $password): ?array
    {
        $user = Db::fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
        if (!$user || $user['status'] !== 'active') {
            // 无论账号是否存在都执行一次哈希校验，降低时序差异。
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $user['id']]);
        }
        return $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = $user;
        self::$resolved = true;

        Db::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $user['id']]);
        Audit::log('auth.login', 'user', (int) $user['id']);
    }

    public static function logout(): void
    {
        $id = self::user()['id'] ?? null;
        if ($id) {
            Audit::log('auth.logout', 'user', (int) $id);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();

        self::$user = null;
        self::$resolved = true;
    }
}
