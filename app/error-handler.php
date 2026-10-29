<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;

set_exception_handler(static function (Throwable $e): void {
    $debug = (bool) config('app.debug', false);
    $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

    error_log(sprintf('[%s] %s: %s in %s:%d', $status, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    $isApi = str_starts_with($path, '/api/')
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

    if ($isApi) {
        $code = match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 419 => 'expired',
            $status === 422 => 'validation_failed',
            $status === 429 => 'too_many_requests',
            $status >= 500 => 'internal_error',
            default => 'error',
        };

        // 保留 HttpException 的 message 语义；非 HttpException 或 5xx 默认不暴露内部细节。
        $message = $e->getMessage();
        if ($status >= 500 && !$debug) {
            $message = '服务暂时不可用，请稍后再试。';
        }

        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($debug) {
            $payload['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        Response::json($payload, $status)->send();
        return;
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
