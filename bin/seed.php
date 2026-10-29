#!/usr/bin/env php
<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Services\DemoSeeder;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$result = DemoSeeder::run();
echo $result['message'] . "\n";
if (empty($result['skipped'])) {
    echo "纪念馆：/m/zhang-mingyuan\n";
    foreach ($result['users'] as $email => $password) {
        echo "{$email} / {$password}\n";
    }
}
