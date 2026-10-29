<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || strlen($_SESSION['csrf']) < 32) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(Request $request): void
    {
        $token = $request->raw('_token') ?? $request->header('X-CSRF-Token');
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            throw new HttpException(419, 'CSRF token mismatch');
        }
    }
}
