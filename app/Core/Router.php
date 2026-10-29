<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:mixed, middleware:array}> */
    private array $routes = [];

    public function get(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    private function add(string $method, string $pattern, mixed $handler, array $middleware): void
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#u',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            foreach ($route['middleware'] as $middleware) {
                $response = $this->runMiddleware($middleware, $request);
                if ($response instanceof Response) {
                    return $response;
                }
            }

            $handler = $route['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $handler[0] = new $handler[0]();
            }

            $result = $handler($request, ...array_values($params));
            if ($result instanceof Response) {
                return $result;
            }
            if (is_string($result)) {
                return Response::html($result);
            }
            throw new \RuntimeException('Route handler returned no response: ' . $route['pattern']);
        }

        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'Method not allowed' : 'Not found');
    }

    private function runMiddleware(string $name, Request $request): ?Response
    {
        return match ($name) {
            'auth' => Auth::check()
                ? null
                : Response::redirect('/login?next=' . rawurlencode($request->path)),
            'guest' => Auth::check()
                ? Response::redirect('/dashboard')
                : null,
            'admin' => Auth::isAdmin()
                ? null
                : (Auth::check()
                    ? throw new HttpException(403, '没有访问权限')
                    : Response::redirect('/login?next=' . rawurlencode($request->path))),
            default => throw new \RuntimeException('Unknown middleware: ' . $name),
        };
    }
}
