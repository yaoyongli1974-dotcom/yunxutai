#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 上线前自检：php bin/doctor.php
 * 退出码 0 表示无阻断项；1 表示存在必须修复的问题。
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$errors = [];
$warnings = [];
$oks = [];

function ok(string $message): void
{
    global $oks;
    $oks[] = $message;
}

function warn(string $message): void
{
    global $warnings;
    $warnings[] = $message;
}

function fail(string $message): void
{
    global $errors;
    $errors[] = $message;
}

echo "静思园 · 上线自检\n\n";

if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
    ok('PHP 版本 ' . PHP_VERSION);
} else {
    fail('PHP 版本过低：' . PHP_VERSION . '，需要 8.4+');
}

foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'gd', 'openssl'] as $extension) {
    if (extension_loaded($extension)) {
        ok('扩展 ' . $extension);
    } else {
        fail('缺少 PHP 扩展：' . $extension);
    }
}

if (config('app.debug')) {
    warn('APP_DEBUG 仍为 true；生产环境应设为 false');
} else {
    ok('APP_DEBUG 已关闭');
}

if (config('app.env') === 'production') {
    ok('APP_ENV=production');
} else {
    warn('APP_ENV 不是 production（当前：' . config('app.env') . '）');
}

$appKey = (string) config('app.key');
if ($appKey === '' || $appKey === 'dev-only-key-change-before-production' || strlen($appKey) < 32) {
    fail('APP_KEY 未设置或仍为默认值，IP 哈希与签名不安全');
} else {
    ok('APP_KEY 已配置');
}

$appUrl = (string) config('app.url');
if ($appUrl === '') {
    warn('APP_URL 为空；邮件/分享链接可能不正确');
} elseif (!str_starts_with($appUrl, 'https://') && config('app.env') === 'production') {
    fail('生产环境 APP_URL 必须为 https://');
} else {
    ok('APP_URL：' . $appUrl);
}

foreach (['storage', 'storage/uploads', 'storage/logs', 'storage/cache', 'storage/backups', 'storage/verifications'] as $dir) {
    $path = BASE_PATH . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (is_dir($path) && is_writable($path)) {
        ok('目录可写：' . $dir);
    } else {
        fail('目录不可写：' . $dir);
    }
}

try {
    Db::value('SELECT 1');
    ok('数据库连接正常（' . config('db.database') . '）');

    $required = ['users', 'memorials', 'memorial_members', 'memorial_verifications', 'tributes', 'messages', 'media', 'reports', 'audit_logs'];
    $missing = [];
    foreach ($required as $table) {
        if (!Db::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table])) {
            $missing[] = $table;
        }
    }
    if ($missing === []) {
        ok('核心数据表完整');
    } else {
        fail('缺少数据表：' . implode(', ', $missing) . '（请执行 php bin/install.php）');
    }

    $admin = Db::value('SELECT COUNT(*) FROM users WHERE role = ? AND status = ?', ['admin', 'active']);
    if ((int) $admin > 0) {
        ok('存在启用的管理员账号');
    } else {
        fail('没有启用的管理员账号');
    }

    $publicMemorials = (int) Db::value("SELECT COUNT(*) FROM memorials WHERE status = 'active' AND visibility = 'public' AND verification_status = 'verified'");
    if ($publicMemorials === 0) {
        warn('尚无公开纪念馆；首页会显示空状态');
    } else {
        ok('公开纪念馆：' . $publicMemorials . ' 座');
    }
} catch (Throwable $e) {
    fail('数据库检查失败：' . $e->getMessage());
}

$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');
if ((int) ini_get('file_uploads') === 1) {
    ok('允许文件上传（upload_max_filesize=' . $uploadMax . ', post_max_size=' . $postMax . '）');
    $toBytes = static function (string $value): int {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    };
    if ($toBytes((string) $uploadMax) < 20 * 1024 * 1024) {
        warn('upload_max_filesize=' . $uploadMax . ' 小于应用允许的 20MB 照片上限；请调整 PHP/Nginx 配置');
    }
    if ($toBytes((string) $postMax) < 21 * 1024 * 1024) {
        warn('post_max_size=' . $postMax . ' 小于照片上传所需；请调整 PHP 配置');
    }
} else {
    fail('PHP file_uploads 已关闭，媒体上传不可用');
}

if (filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOL) === false && config('app.env') === 'production') {
    warn('session.cookie_secure 未开启；生产环境应启用 HTTPS 与 Secure Cookie');
}

if (config('ai.enabled')) {
    $provider = (string) config('ai.provider');
    ok('AI 工作室已启用（provider=' . $provider . '）');
    if ($provider === 'openai' && trim((string) config('ai.api_key')) === '') {
        warn('AI_PROVIDER=openai 但 AI_API_KEY 为空；远程生成会失败');
    }
    if (!is_file((string) config('ai.ffmpeg'))) {
        warn('ffmpeg 不存在：' . config('ai.ffmpeg') . '；纪念短片无法生成');
    }
    try {
        \App\Core\Db::value('SELECT COUNT(*) FROM ai_services');
    } catch (\Throwable $e) {
        warn('AI 数据表缺失，请执行 php bin/migrate.php');
    }
} else {
    warn('AI 工作室未启用（可选）：设置 AI_ENABLED=true 后可用');
}

$redisUrl = trim((string) config('kv.redis_url', ''));
if ($redisUrl !== '') {
    $redisClient = null;
    try {
        $redisClient = new \App\Core\Kv\RespClient($redisUrl);
        $pong = $redisClient->command('PING');
        if ($pong === 'PONG') {
            ok('Redis 连接正常（PING）');
        } else {
            warn('Redis PING 返回异常：' . var_export($pong, true));
        }
    } catch (\Throwable $e) {
        warn('Redis 连接失败：' . $e->getMessage() . '（限流与会话将不可用）');
    }

    if ($redisClient !== null) {
        $probeKey = 'jingsi:doctor:' . bin2hex(random_bytes(6));
        try {
            $redisClient->command('SETEX', $probeKey, '10', 'ok');
            $probeValue = $redisClient->command('GET', $probeKey);
            if ($probeValue === 'ok') {
                ok('Redis 键值读写正常');
            } else {
                warn('Redis 键值读写异常：SETEX/GET 结果不一致');
            }
        } catch (\Throwable $e) {
            warn('Redis 键值读写失败：' . $e->getMessage());
        } finally {
            try {
                $redisClient->command('DEL', $probeKey);
            } catch (\Throwable $e) {
                // 清理失败不追加警告，避免自检输出噪音。
            }
        }
    } else {
        warn('Redis 键值读写检查已跳过：连接不可用');
    }
}

echo "\n通过：" . count($oks) . "\n";
foreach ($oks as $item) {
    echo "  ✓ {$item}\n";
}

if ($warnings !== []) {
    echo "\n警告：" . count($warnings) . "\n";
    foreach ($warnings as $item) {
        echo "  ! {$item}\n";
    }
}

if ($errors !== []) {
    echo "\n阻断项：" . count($errors) . "\n";
    foreach ($errors as $item) {
        echo "  ✗ {$item}\n";
    }
    exit(1);
}

echo "\n自检完成，无阻断项。\n";
exit(0);
