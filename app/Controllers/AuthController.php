<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Captcha;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Totp;
use App\Core\Validator;
use App\Core\View;
use App\Models\User;

final class AuthController
{
    private const PENDING_2FA_KEY = 'pending_2fa';
    private const PENDING_2FA_TTL = 600;

    public function showRegister(Request $request): Response
    {
        return $this->renderRegister([], []);
    }

    public function register(Request $request): Response
    {
        Csrf::verify($request);

        if (!RateLimiter::hit('register:' . $request->ip(), 5, 3600)) {
            return View::render('auth/register', [
                'title' => '创建账号',
                'errors' => ['form' => '操作过于频繁，请稍后再试'],
                'old' => $request->post,
                'captcha' => Captcha::question(),
            ], 'layout', 429);
        }

        $data = [
            'display_name' => trim((string) $request->input('display_name', '')),
            'email' => mb_strtolower(trim((string) $request->input('email', ''))),
            'password' => (string) $request->raw('password', ''),
        ];
        $confirm = (string) $request->raw('password_confirmation', '');

        $validator = Validator::make($data, [
            'display_name' => 'required|min:2|max:40',
            'email' => 'required|email|max:190',
            'password' => 'required|min:10|max:72',
        ], [
            'display_name' => '称呼',
            'email' => '邮箱',
            'password' => '密码',
        ]);

        $errors = $validator->errors();

        if ($data['password'] !== $confirm) {
            $errors['password_confirmation'] = '两次输入的密码不一致';
        }
        if (($problem = Validator::passwordProblem($data['password'])) !== null) {
            $errors['password'] = $problem;
        }
        if (User::findByEmail($data['email'])) {
            $errors['email'] = '该邮箱已注册，请直接登录';
        }
        if (!$request->bool('terms')) {
            $errors['terms'] = '请阅读并同意服务条款与隐私说明';
        }
        if (!Captcha::verify($request->input('captcha'))) {
            $errors['captcha'] = '验证码不正确或已过期';
        }

        if ($errors !== []) {
            return View::render('auth/register', [
                'title' => '创建账号',
                'errors' => $errors,
                'old' => ['display_name' => $data['display_name'], 'email' => $data['email']],
                'captcha' => Captcha::question(),
            ], 'layout', 422);
        }

        $userId = User::create($data['email'], $data['password'], $data['display_name']);
        $user = User::find($userId);
        Auth::login($user);

        Flash::success('账号已创建。你可以先为亲人建立一座纪念馆。');
        return Response::redirect('/dashboard');
    }

    public function showLogin(Request $request): Response
    {
        return View::render('auth/login', [
            'title' => '登录',
            'errors' => [],
            'old' => [],
            'next' => $this->safeNext((string) ($request->query['next'] ?? '')),
        ]);
    }

    public function login(Request $request): Response
    {
        Csrf::verify($request);

        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->raw('password', '');
        $next = $this->safeNext((string) $request->input('next', ''));

        if (RateLimiter::blocked('login-ip:' . $request->ip(), 30)) {
            return View::render('auth/login', [
                'title' => '登录',
                'errors' => ['form' => '尝试次数过多，请稍后再试'],
                'old' => ['email' => $email],
                'next' => $next,
            ], 'layout', 429);
        }
        if (!RateLimiter::hit('login:' . $request->ip() . ':' . $email, (int) config('security.login_max_attempts'), (int) config('security.login_window'))) {
            RateLimiter::hit('login-ip:' . $request->ip(), 30, 3600);
            return View::render('auth/login', [
                'title' => '登录',
                'errors' => ['form' => '尝试次数过多，请 15 分钟后再试'],
                'old' => ['email' => $email],
                'next' => $next,
            ], 'layout', 429);
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => 'required|email', 'password' => 'required'],
            ['email' => '邮箱', 'password' => '密码']
        );

        $errors = $validator->errors();
        if ($errors === []) {
            $user = Auth::attempt($email, $password);
            if ($user === null) {
                $errors['form'] = '邮箱或密码不正确';
            } else {
                RateLimiter::clear('login:' . $request->ip() . ':' . $email);
                if ((int) ($user['totp_enabled'] ?? 0) === 1) {
                    // 密码通过但二次验证未完成：此时不建立登录态，只保存短时待验证上下文。
                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    $_SESSION[self::PENDING_2FA_KEY] = [
                        'uid' => (int) $user['id'],
                        'next' => $next,
                        'at' => time(),
                    ];
                    return Response::redirect('/2fa');
                }
                Auth::login($user);
                Flash::success('欢迎回来，' . $user['display_name'] . '。');
                return Response::redirect($next);
            }
        }

        return View::render('auth/login', [
            'title' => '登录',
            'errors' => $errors,
            'old' => ['email' => $email],
            'next' => $next,
        ], 'layout', 422);
    }

    public function showTwofactor(Request $request): Response
    {
        $pending = $this->pendingTwofactor();
        if ($pending === null) {
            return Response::redirect('/login');
        }

        $user = User::find((int) $pending['uid']);
        if ($user === null || $user['status'] !== 'active' || (int) $user['totp_enabled'] !== 1 || (string) ($user['totp_secret'] ?? '') === '') {
            $this->clearPending();
            Flash::error('二次验证状态已变更，请重新登录。');
            return Response::redirect('/login');
        }

        return View::render('auth/twofactor', [
            'title' => '二次验证',
            'errors' => [],
        ]);
    }

    public function loginTwofactorVerify(Request $request): Response
    {
        Csrf::verify($request);

        $pending = $this->pendingTwofactor();
        if ($pending === null) {
            return Response::redirect('/login');
        }

        $user = User::find((int) $pending['uid']);
        if ($user === null || $user['status'] !== 'active' || (int) $user['totp_enabled'] !== 1 || (string) ($user['totp_secret'] ?? '') === '') {
            $this->clearPending();
            Flash::error('二次验证状态已变更，请重新登录。');
            return Response::redirect('/login');
        }

        $key = '2fa:' . $request->ip() . ':' . (int) $user['id'];
        if (!RateLimiter::hit($key, (int) config('security.login_max_attempts'), (int) config('security.login_window'))) {
            return View::render('auth/twofactor', [
                'title' => '二次验证',
                'errors' => ['form' => '尝试次数过多，请 15 分钟后再试'],
            ], 'layout', 429);
        }

        $code = (string) $request->raw('code', '');
        $errors = [];
        if (!preg_match('/^\d{6}$/', $code)) {
            $errors['code'] = '请输入 6 位动态验证码';
        } elseif (!Totp::verifyCode((string) $user['totp_secret'], $code)) {
            $errors['code'] = '验证码不正确，请确认身份验证器时间准确';
        }

        if ($errors !== []) {
            return View::render('auth/twofactor', [
                'title' => '二次验证',
                'errors' => $errors,
            ], 'layout', 422);
        }

        RateLimiter::clear($key);
        $next = (string) ($pending['next'] ?? '/dashboard');
        $this->clearPending();
        Auth::login($user);
        Flash::success('欢迎回来，' . $user['display_name'] . '。');
        return Response::redirect($next);
    }

    public function logout(Request $request): Response
    {
        Csrf::verify($request);
        Auth::logout();
        Flash::info('已安全退出。');
        return Response::redirect('/');
    }

    private function renderRegister(array $errors, array $old): Response
    {
        return View::render('auth/register', [
            'title' => '创建账号',
            'errors' => $errors,
            'old' => $old,
            'captcha' => Captcha::question(),
        ]);
    }

    private function safeNext(string $next): string
    {
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            return $next;
        }
        return '/dashboard';
    }

    private function pendingTwofactor(): ?array
    {
        $pending = $_SESSION[self::PENDING_2FA_KEY] ?? null;
        if (!is_array($pending) || !isset($pending['uid'], $pending['at'])) {
            return null;
        }
        if (time() - (int) $pending['at'] > self::PENDING_2FA_TTL) {
            $this->clearPending();
            return null;
        }
        return $pending;
    }

    private function clearPending(): void
    {
        unset($_SESSION[self::PENDING_2FA_KEY]);
    }
}
