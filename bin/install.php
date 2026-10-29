#!/usr/bin/env php
<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Models\User;
use App\Services\DemoSeeder;
use App\Models\AiService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['fresh', 'demo']);
$fresh = isset($options['fresh']);
$demo = isset($options['demo']);
$dbName = (string) config('db.database');

if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "数据库名不合法：{$dbName}\n");
    exit(1);
}

echo "静思园 · 安装程序\n";
echo "数据库：{$dbName}\n\n";

// 生成 .env（仅在不存在时）
if (!is_file(BASE_PATH . '/.env')) {
    $template = (string) file_get_contents(BASE_PATH . '/.env.example');
    $replacements = [
        'APP_KEY=' => 'APP_KEY=' . bin2hex(random_bytes(32)),
        'DB_HOST=' => 'DB_HOST=' . config('db.host'),
        'DB_PORT=' => 'DB_PORT=' . config('db.port'),
        'DB_DATABASE=' => 'DB_DATABASE=' . $dbName,
        'DB_USERNAME=' => 'DB_USERNAME=' . config('db.username'),
        'DB_PASSWORD=' => 'DB_PASSWORD=' . config('db.password'),
    ];
    foreach ($replacements as $search => $replace) {
        $template = preg_replace('/^' . preg_quote($search, '/') . '.*$/m', $replace, $template) ?? $template;
    }
    file_put_contents(BASE_PATH . '/.env', $template);
    echo "已生成 .env（含随机 APP_KEY），请按需检查配置。\n";
}

try {
    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db.host'), config('db.port'));
    $server = new PDO($serverDsn, (string) config('db.username'), (string) config('db.password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if ($fresh) {
        echo "⚠ --fresh：正在删除数据库 `{$dbName}` 并重建。\n";
        $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }

    $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "数据库已就绪。\n";

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db.host'), config('db.port'), $dbName),
        (string) config('db.username'),
        (string) config('db.password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    $schema = preg_replace('/^--.*$/m', '', $schema) ?? $schema;
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    echo '数据表已创建/校验完成（' . count($statements) . " 条语句）。\n";

    AiService::ensureDefaults();

    foreach (['uploads', 'logs', 'cache', 'verifications'] as $dir) {
        $path = BASE_PATH . '/storage/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    $adminEmail = (string) config('app.admin_email');
    $admin = User::findByEmail($adminEmail);
    if ($admin !== null) {
        User::update((int) $admin['id'], ['role' => 'admin', 'status' => 'active']);
        echo "管理员账号已存在：{$adminEmail}（已确保管理员权限，密码未改动）\n";
    } else {
        $password = (string) (getenv('ADMIN_PASSWORD') ?: '');
        if ($password === '') {
            $password = 'Adm-' . bin2hex(random_bytes(6)) . random_int(10, 99);
        }
        $id = User::create($adminEmail, $password, '平台管理员');
        User::update($id, [
            'role' => 'admin',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
        echo "管理员已创建：\n  邮箱：{$adminEmail}\n  密码：{$password}\n  请登录后立即修改密码。\n";
    }

    if ($demo) {
        echo "\n正在写入演示数据……\n";
        $result = DemoSeeder::run();
        echo $result['message'] . "\n";
        if (empty($result['skipped'])) {
            echo "演示纪念馆：/m/zhang-mingyuan\n";
            echo "演示账号：\n";
            foreach ($result['users'] as $email => $password) {
                echo "  {$email} / {$password}\n";
            }
        }
    }

    echo "\n安装完成。本地启动：php -S 127.0.0.1:8099 -t public\n";
    if ((bool) config('app.debug')) {
        echo "提示：生产环境请设置 APP_DEBUG=false、APP_URL=https://你的域名，并启用 HTTPS。\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, '安装失败：' . $e->getMessage() . "\n");
    exit(1);
}
