<?php
declare(strict_types=1);

const APP_VERSION = '0.1.0';

$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && in_array($value[0], ['"', "'"], true) && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require BASE_PATH . '/app/helpers.php';

$GLOBALS['config'] = require BASE_PATH . '/config/config.php';

date_default_timezone_set(config('app.timezone', 'Asia/Shanghai'));
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name((string) config('security.session_name', 'jingsi_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    // 多实例部署可选：配置 REDIS_URL 后改用 Redis 共享会话；留空时仍使用 PHP 默认文件存储。
    $redisUrl = trim((string) config('kv.redis_url', ''));
    if ($redisUrl !== '') {
        session_set_save_handler(
            new \App\Core\Kv\RedisSessionHandler(new \App\Core\Kv\RespClient($redisUrl)),
            true
        );
    }

    // 后台「AI 设置」等平台设置：DB 值覆盖 .env 默认（表不存在/未配置则静默跳过）。
    if (is_file(BASE_PATH . '/app/Core/Settings.php')) {
        try {
            \App\Core\Settings::load();
        } catch (\Throwable) {
            // 数据库暂不可用时保持 env 默认值。
        }
    }

    session_start();

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

require BASE_PATH . '/app/error-handler.php';
