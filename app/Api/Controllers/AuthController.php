<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Input;
use App\Api\Presenter;
use App\Api\WechatLogin;
use App\Core\ApiAuth;
use App\Core\Auth;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

final class AuthController extends BaseController
{
    /**
     * POST /api/v1/auth/token — 邮箱密码换 API token。
     * 凭证错误统一提示，不区分账号是否存在（Auth::attempt 内部已做过时序拉平）。
     */
    public function token(Request $request): Response
    {
        if (!RateLimiter::hit('api-token:' . $request->ip(), 5, 300)) {
            throw ApiException::rateLimited('尝试次数过多，请 5 分钟后再试');
        }

        $email = mb_strtolower(Input::string($request, 'email'));
        $rawPassword = Input::get($request, 'password', '');
        // 密码不做 trim（首尾空格可能是密码的一部分）。
        $password = is_scalar($rawPassword) ? (string) $rawPassword : '';

        $fields = [];
        if ($email === '') {
            $fields['email'] = '邮箱不能为空';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = '邮箱格式不正确';
        }
        if ($password === '') {
            $fields['password'] = '密码不能为空';
        }
        if ($fields !== []) {
            throw ApiException::validation('请填写完整的邮箱和密码', $fields);
        }

        $user = Auth::attempt($email, $password);
        if ($user === null) {
            throw ApiException::unauthorized('邮箱或密码不正确');
        }

        if (!class_exists(ApiAuth::class)) {
            throw new ApiException('service_unavailable', 'API 认证服务尚未就绪', 503);
        }

        $token = ApiAuth::createToken($user, 'miniapp');
        RateLimiter::clear('api-token:' . $request->ip());

        return Api::ok([
            'token' => (string) $token['token'],
            'expires_at' => (string) $token['expires_at'],
            'user' => Presenter::user($user),
        ]);
    }

    /**
     * POST /api/v1/auth/wechat — 小程序静默登录。
     * 逻辑全部封装在 App\Api\WechatLogin（含 pending-migration 说明）。
     */
    public function wechat(Request $request): Response
    {
        $code = Input::string($request, 'code');
        if ($code === '') {
            throw ApiException::validation('缺少 code', ['code' => 'code 不能为空']);
        }

        return Api::ok(WechatLogin::authenticate($code));
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): Response
    {
        if (!class_exists(ApiAuth::class)) {
            throw new ApiException('service_unavailable', 'API 认证服务尚未就绪', 503);
        }
        ApiAuth::revoke();
        return Api::ok(['ok' => true]);
    }

    /**
     * GET /api/v1/me
     */
    public function me(Request $request): Response
    {
        $user = $this->user();
        $rows = Db::fetchAll(
            "SELECT mm.memorial_id, mm.role, m.slug, m.title
             FROM memorial_members mm
             JOIN memorials m ON m.id = mm.memorial_id
             WHERE mm.user_id = ? AND mm.status = 'active'
             ORDER BY m.updated_at DESC",
            [(int) $user['id']]
        );

        return Api::ok([
            'user' => Presenter::user($user),
            'memberships' => array_map(
                static fn (array $row): array => Presenter::membership($row),
                $rows
            ),
        ]);
    }
}
