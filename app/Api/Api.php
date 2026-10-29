<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Request;
use App\Core\Response;

/**
 * JSON 响应与路由包装的公共入口。
 * 所有端点都经 Api::guard()，保证异常不会落到网页端 HTML 错误页。
 */
final class Api
{
    public static function ok(array $data, int $status = 200): Response
    {
        return Response::json(['data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status, array $extra = []): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                ...$extra,
            ],
        ], $status);
    }

    /**
     * 统一异常边界：ApiException 转业务 JSON，其它异常记录日志后转 internal_error。
     */
    public static function guard(callable $handler): Response
    {
        try {
            $result = $handler();
            if ($result instanceof Response) {
                return $result;
            }
            error_log('[api] route handler returned no response');
            return self::error('internal_error', '服务暂时不可用，请稍后再试', 500);
        } catch (ApiException $e) {
            return self::error(
                $e->errorCode,
                $e->getMessage(),
                $e->status,
                $e->fields !== [] ? ['fields' => $e->fields] : []
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[api] %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            return self::error('internal_error', '服务暂时不可用，请稍后再试', 500);
        }
    }

    /**
     * 路由目标包装：$target = [Controller::class, 'method']。
     */
    public static function route(array $target): \Closure
    {
        [$class, $method] = $target;

        return static function (Request $request, mixed ...$params) use ($class, $method): Response {
            return self::guard(static function () use ($class, $method, $request, $params): Response {
                $controller = new $class();
                return $controller->{$method}($request, ...$params);
            });
        };
    }

    /**
     * @return array{0:int,1:int} [page, perPage]
     */
    public static function page(Request $request, int $defaultPerPage = 12, int $maxPerPage = 20): array
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = (int) ($request->query['per_page'] ?? $defaultPerPage);
        $perPage = max(1, min($maxPerPage, $perPage));
        return [$page, $perPage];
    }

    public static function pagination(int $total, int $page, int $perPage): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }
}
