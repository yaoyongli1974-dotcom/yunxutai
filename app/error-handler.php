<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\View;

set_exception_handler(static function (Throwable $e): void {
    $debug = (bool) config('app.debug', false);
    $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

    error_log(sprintf('[%s] %s: %s in %s:%d', $status, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $titles = [
        403 => '没有访问权限',
        404 => '页面不存在',
        405 => '请求方式不被允许',
        419 => '页面已过期',
        429 => '操作过于频繁',
    ];

    if ($debug) {
        View::render('errors/debug', [
            'title' => $titles[$status] ?? '出错了',
            'exception' => $e,
            'status' => $status,
        ], 'layout', $status)->send();
        return;
    }

    View::render('errors/error', [
        'title' => $titles[$status] ?? '暂时无法访问',
        'status' => $status,
        'message' => $status < 500 ? $e->getMessage() : '服务暂时不可用，请稍后再试。',
    ], 'layout', $status)->send();
});
