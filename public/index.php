<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Core\Request;

$router = require BASE_PATH . '/app/routes.php';

// 小程序 JSON API 路由（可选）：app/routes-api.php 不是独立路由器，
// 而是返回一个接收主 Router 的闭包：`return static function (Router $router): void { ... };`
// 这里把它注册进同一个 $router，最终仍只 dispatch 主路由器。
$registerApiRoutes = is_file(BASE_PATH . '/app/routes-api.php')
    ? require BASE_PATH . '/app/routes-api.php'
    : null;
if (is_callable($registerApiRoutes)) {
    $registerApiRoutes($router);
}

$request = Request::fromGlobals();
$router->dispatch($request)->send();
