<?php
declare(strict_types=1);

namespace App\Core;

final class Captcha
{
    /** @return array{a:int,b:int} */
    public static function question(): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $_SESSION['_captcha'] = [
            'answer' => (string) ($a + $b),
            'expires' => time() + (int) config('security.captcha_ttl', 600),
        ];
        return ['a' => $a, 'b' => $b];
    }

    public static function verify(mixed $input): bool
    {
        $captcha = $_SESSION['_captcha'] ?? null;
        unset($_SESSION['_captcha']);

        if (!is_array($captcha) || (int) ($captcha['expires'] ?? 0) < time()) {
            return false;
        }
        return is_scalar($input) && hash_equals((string) $captcha['answer'], trim((string) $input));
    }
}
