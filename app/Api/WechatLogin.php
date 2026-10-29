<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\ApiAuth;
use App\Core\Db;

/**
 * 微信小程序登录（jscode2session 换 openid）。
 *
 * openid 持久化：`wx_identities` 表绑定 openid ↔ users。
 * 首次登录自动创建账号：合成邮箱 `wx-<hash>@wx.local`（不可用密码，仅可通过
 * 微信登录进入），display_name 取 '微信用户' + openid 摘要后 6 位。
 *
 * 配置读取顺序：config('wechat.appid'|'wechat.secret') → 环境变量 WX_APPID/WX_SECRET。
 */
final class WechatLogin
{
    private const ENDPOINT = 'https://api.weixin.qq.com/sns/jscode2session';
    private const TIMEOUT = 5;

    /** 最近一次 jscode2session 返回的 unionid（可能为空）。 */
    private static string $lastUnionid = '';

    /**
     * @return array{token:string, expires_at:string, user:array}
     */
    public static function authenticate(string $code): array
    {
        if (!self::isConfigured()) {
            throw new ApiException('wechat_not_configured', '小程序登录暂未配置', 503);
        }

        $openid = self::exchangeOpenid($code);
        $userId = self::ensureIdentity($openid, self::$lastUnionid);

        $user = Db::fetch('SELECT * FROM users WHERE id = ? AND status = ? LIMIT 1', [$userId, 'active']);
        if ($user === null) {
            throw ApiException::unauthorized('账号不可用，请联系客服');
        }
        if (!class_exists(ApiAuth::class)) {
            throw new ApiException('service_unavailable', 'API 认证服务尚未就绪', 503);
        }

        $token = ApiAuth::createToken($user, 'miniapp-wechat');

        return [
            'token' => (string) $token['token'],
            'expires_at' => (string) $token['expires_at'],
            'user' => Presenter::user($user),
        ];
    }

    public static function isConfigured(): bool
    {
        $appId = (string) (config('wechat.appid') ?: getenv('WX_APPID') ?: '');
        $secret = (string) (config('wechat.secret') ?: getenv('WX_SECRET') ?: '');
        return trim($appId) !== '' && trim($secret) !== '';
    }

    /**
     * GET jscode2session（无 curl 扩展，使用 allow_url_fopen + stream context 超时）。
     */
    private static function exchangeOpenid(string $code): string
    {
        $query = http_build_query([
            'appid' => (string) (config('wechat.appid') ?: getenv('WX_APPID')),
            'secret' => (string) (config('wechat.secret') ?: getenv('WX_SECRET')),
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $raw = @file_get_contents(self::ENDPOINT . '?' . $query, false, $context);
        if ($raw === false) {
            throw new ApiException('service_unavailable', '微信服务暂时不可用，请稍后再试', 503);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new ApiException('wechat_exchange_failed', '微信返回数据无法解析', 501);
        }
        if ((int) ($payload['errcode'] ?? 0) !== 0) {
            $message = (string) ($payload['errmsg'] ?? 'unknown error');
            throw new ApiException('wechat_exchange_failed', '微信登录失败：' . $message, 501);
        }

        $openid = trim((string) ($payload['openid'] ?? ''));
        if ($openid === '') {
            throw new ApiException('wechat_exchange_failed', '微信未返回 openid', 501);
        }
        self::$lastUnionid = trim((string) ($payload['unionid'] ?? ''));
        return $openid;
    }

    /**
     * 查询或创建 openid 绑定，返回 user_id。
     * 首次登录自动建号：合成邮箱 + 不可用密码，只能经微信登录进入。
     *
     * @return int 用户 ID
     */
    public static function ensureIdentity(string $openid, string $unionid = ''): int
    {
        $openid = trim($openid);
        if ($openid === '') {
            throw new ApiException('wechat_exchange_failed', '微信未返回 openid', 501);
        }
        $unionid = trim($unionid) ?: null;

        $existing = Db::fetch(
            'SELECT id, user_id, unionid FROM wx_identities WHERE openid = ? LIMIT 1',
            [$openid]
        );
        if ($existing !== null) {
            if ($unionid !== null && $unionid !== ($existing['unionid'] ?? null)) {
                Db::query(
                    'UPDATE wx_identities SET last_login_at = CURRENT_TIMESTAMP, unionid = ? WHERE id = ?',
                    [$unionid, (int) $existing['id']]
                );
            } else {
                Db::query(
                    'UPDATE wx_identities SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [(int) $existing['id']]
                );
            }
            return (int) $existing['user_id'];
        }

        $email = self::syntheticEmail($openid);
        $displayName = '微信用户' . substr(md5($openid), -6);
        $unusableHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        try {
            return (int) Db::transaction(function () use ($openid, $unionid, $email, $displayName, $unusableHash) {
                $raced = Db::fetch('SELECT user_id FROM wx_identities WHERE openid = ? LIMIT 1', [$openid]);
                if ($raced !== null) {
                    return (int) $raced['user_id'];
                }
                $userId = Db::insert('users', [
                    'email' => $email,
                    'password_hash' => $unusableHash,
                    'display_name' => $displayName,
                    'role' => 'user',
                    'status' => 'active',
                    'last_login_at' => date('Y-m-d H:i:s'),
                ]);
                Db::insert('wx_identities', [
                    'user_id' => $userId,
                    'openid' => $openid,
                    'unionid' => $unionid,
                    'provider' => 'miniapp',
                    'last_login_at' => date('Y-m-d H:i:s'),
                ]);
                return $userId;
            });
        } catch (Throwable $e) {
            throw new \RuntimeException('微信登录账号创建失败：' . $e->getMessage());
        }
    }

    /**
     * 合成邮箱：wx-<md5(openid)>@wx.local，稳定、唯一、不与真实邮箱冲突。
     */
    public static function syntheticEmail(string $openid): string
    {
        return 'wx-' . md5($openid) . '@wx.local';
    }
}
