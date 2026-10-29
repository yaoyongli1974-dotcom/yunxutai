<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 小程序 / 移动端 Bearer Token 认证。
 *
 * Token 仅以 sha256 散列入库，明文只在创建时返回一次。
 * 解析成功后会把用户写入 Auth，使 Auth::user()/check()/isAdmin() 在 API 层继续可用。
 */
final class ApiAuth
{
    private const TOKEN_TTL_DAYS = 30;

    /**
     * 为指定用户签发 token。
     *
     * @return array{token:string, expires_at:string}
     */
    public static function createToken(array $user, string $label): array
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = date('Y-m-d H:i:s', $now + self::TOKEN_TTL_DAYS * 86400);

        Db::insert('api_tokens', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'label' => mb_substr(trim($label), 0, 20),
            'created_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * 解析 Authorization: Bearer <token>；失败返回 null，不发送任何响应。
     */
    public static function resolve(): ?array
    {
        $token = self::bearerToken();
        if ($token === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $row = Db::fetch(
            'SELECT t.id AS token_id, u.*
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > ?)
               AND u.status = ?
             LIMIT 1',
            [hash('sha256', $token), $now, 'active']
        );

        if ($row === null) {
            return null;
        }

        $tokenId = (int) $row['token_id'];
        unset($row['token_id']);

        Db::update('api_tokens', ['last_used_at' => $now], 'id = ?', [$tokenId]);
        Auth::setApiUser($row);

        return $row;
    }

    /**
     * 撤销当前请求携带的 Bearer token。
     */
    public static function revoke(): void
    {
        $token = self::bearerToken();
        if ($token === null) {
            return;
        }

        Db::update(
            'api_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'token_hash = ? AND revoked_at IS NULL',
            [hash('sha256', $token)]
        );
    }

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (!is_string($header) || $header === '') {
            // 部分 SAPI 不会填充 HTTP_AUTHORIZATION，回退到 getallheaders()。
            if (function_exists('getallheaders')) {
                $headers = getallheaders();
                if (is_array($headers)) {
                    foreach ($headers as $name => $value) {
                        if (strcasecmp((string) $name, 'Authorization') === 0) {
                            $header = (string) $value;
                            break;
                        }
                    }
                }
            }
        }

        if (!is_string($header) || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        return $token === '' ? null : $token;
    }
}
