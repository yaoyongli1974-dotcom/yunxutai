<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Core\Request;

$router = require BASE_PATH . '/app/routes.php';
$request = Request::fromGlobals();
$router->dispatch($request)->send();
