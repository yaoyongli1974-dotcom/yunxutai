<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\ApiAuth;
use App\Core\Db;

/**
 * 微信小程序登录（jscode2session 换 openid）。
 *
 * pending-migration:
 *   users 表当前没有 openid 列，也没有独立的第三方身份表，schema 由 Lane A 持有，
 *   API Lane 不得自行改库。因此本服务在成功换到 openid 后**不会**生成任何
 *   `wx_{sha1}@...` 之类的合成邮箱占位账号，而是明确返回 503
 *   `wechat_not_configured: openid 持久化待迁移`。
 *
 *   真实启用前需要新增（由 schema 的所有者处理）：
 *     wx_identities(id, openid UNIQUE, unionid, user_id, created_at, last_login_at)
 *   或给 users 增加 openid 列；随后在 self::userIdForOpenid() 中接入即可，
 *   控制器的响应结构无需改动。
 *
 * 配置读取顺序：config('wechat.appid'|'wechat.secret') → 环境变量 WX_APPID/WX_SECRET。
 */
final class WechatLogin
{
    private const ENDPOINT = 'https://api.weixin.qq.com/sns/jscode2session';
    private const TIMEOUT = 5;

    /**
     * @return array{token:string, expires_at:string, user:array}
     */
    public static function authenticate(string $code): array
    {
        if (!self::isConfigured()) {
            throw new ApiException('wechat_not_configured', '小程序登录暂未配置', 503);
        }

        $openid = self::exchangeOpenid($code);
        $userId = self::userIdForOpenid($openid);
        if ($userId === null) {
            // pending-migration：openid 无处落库（见类注释）。接入 wx_identities 后此处改为查/建用户。
            throw new ApiException('wechat_not_configured', 'openid 持久化待迁移', 503);
        }

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
        return $openid;
    }

    /**
     * pending-migration：目前恒为 null（users 无 openid 列）。
     * 若后续 schema 增加 users.openid，这里会自动识别并查用户。
     */
    private static function userIdForOpenid(string $openid): ?int
    {
        $hasColumn = (bool) Db::value(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'openid' LIMIT 1"
        );
        if (!$hasColumn) {
            return null;
        }

        $id = Db::value('SELECT id FROM users WHERE openid = ? LIMIT 1', [$openid]);
        return $id === null ? null : (int) $id;
    }
}
