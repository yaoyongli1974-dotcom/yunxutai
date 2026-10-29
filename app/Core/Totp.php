<?php
declare(strict_types=1);

namespace App\Core;

/**
 * RFC 6238 TOTP（SHA1 / 6 位 / 30 秒），只依赖 PHP 内置哈希与随机数。
 *
 * 密钥以 RFC 4648 Base32（无填充）保存，恰好 32 个字符（20 字节）。
 * 本类不写日志、不拼接 SQL，调用方也不得把密钥写入审计或错误信息。
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const SECRET_BYTES = 20;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * 计算指定时间戳所在时间片的校验码；不传时间戳时使用当前时间。
     */
    public static function at(string $secret, ?int $timestamp = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException('TOTP secret is not valid Base32');
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $binary = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 校验用户输入的 6 位验证码；允许前后各 1 个时间片，容忍客户端时钟偏差。
     */
    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $now = time();
            for ($offset = -$window; $offset <= $window; $offset++) {
                if (hash_equals(self::at($secret, $now + $offset * self::PERIOD), $code)) {
                    return true;
                }
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        return false;
    }

    /**
     * 身份验证器 App 的 otpauth URI。按交付约定，issuer 保留中文原文。
     * 当前只输出文本供手动输入；后续可接入本地 QR 库渲染二维码图片。
     */
    public static function otpauthUri(string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode('静思园:' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=静思园&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    private static function base32Encode(string $bytes): string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::ALPHABET[($buffer >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bits)) & 0x1F];
        }

        return $output;
    }

    private static function base32Decode(string $secret): ?string
    {
        $secret = strtoupper(preg_replace('/[\s=]+/', '', $secret) ?? '');
        if ($secret === '') {
            return null;
        }

        $output = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }
}
