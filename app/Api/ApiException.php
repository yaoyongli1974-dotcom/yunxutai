<?php
declare(strict_types=1);

namespace App\Api;

/**
 * API 层业务异常：携带机器可读的 code 与 HTTP 状态，
 * 由 Api::guard() 统一转换为 {"error":{code,message}} JSON。
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $message = '请先登录'): self
    {
        return new self('unauthorized', $message, 401);
    }

    public static function forbidden(string $message = '没有访问权限'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = '纪念馆不存在'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function validation(string $message, array $fields = []): self
    {
        return new self('validation_failed', $message, 422, $fields);
    }

    public static function rateLimited(string $message = '操作过于频繁，请稍后再试'): self
    {
        return new self('rate_limited', $message, 429);
    }
}
