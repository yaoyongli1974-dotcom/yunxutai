<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Totp;
use App\Core\Validator;
use App\Core\View;
use App\Models\DataRequest;
use App\Models\User;

final class AccountController
{
    public function show(Request $request): Response
    {
        $user = Auth::user();
        $tab = (string) ($request->query['tab'] ?? 'profile');
        if (!in_array($tab, ['profile', 'security', 'privacy', '2fa'], true)) {
            $tab = 'profile';
        }

        $data = [
            'title' => '账号设置',
            'user' => $user,
            'requests' => DataRequest::forUser((int) $user['id']),
            'errors' => [],
            'tab' => $tab,
        ];
        if ($tab === '2fa') {
            $data += $this->twofactorViewData($user);
        }

        return View::render('account/settings', $data);
    }

    public function updateProfile(Request $request): Response
    {
        Csrf::verify($request);
        $user = Auth::user();

        $data = [
            'display_name' => trim((string) $request->input('display_name', '')),
            'phone' => trim((string) $request->input('phone', '')),
        ];
        $validator = Validator::make($data, [
            'display_name' => 'required|min:2|max:40',
            'phone' => 'max:32',
        ], ['display_name' => '称呼', 'phone' => '手机号']);

        $errors = $validator->errors();
        if ($data['phone'] !== '' && !preg_match('/^[0-9+\-\s]{5,32}$/', $data['phone'])) {
            $errors['phone'] = '手机号格式不正确';
        }
        if ($errors !== []) {
            return View::render('account/settings', [
                'title' => '账号设置',
                'user' => $user,
                'requests' => DataRequest::forUser((int) $user['id']),
                'errors' => $errors,
                'tab' => 'profile',
            ], 'layout', 422);
        }

        User::update((int) $user['id'], [
            'display_name' => $data['display_name'],
            'phone' => $data['phone'] ?: null,
        ]);
        Audit::log('account.update_profile', 'user', (int) $user['id']);
        Flash::success('资料已更新。');
        return Response::redirect('/account?tab=profile');
    }

    public function updatePassword(Request $request): Response
    {
        Csrf::verify($request);
        $user = Auth::user();

        $current = (string) $request->raw('current_password', '');
        $password = (string) $request->raw('password', '');
        $confirm = (string) $request->raw('password_confirmation', '');
        $errors = [];

        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = '当前密码不正确';
        }
        if (($problem = Validator::passwordProblem($password)) !== null) {
            $errors['password'] = $problem;
        }
        if ($password !== $confirm) {
            $errors['password_confirmation'] = '两次输入的密码不一致';
        }

        if ($errors !== []) {
            return View::render('account/settings', [
                'title' => '账号设置',
                'user' => $user,
                'requests' => DataRequest::forUser((int) $user['id']),
                'errors' => $errors,
                'tab' => 'security',
            ], 'layout', 422);
        }

        User::update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        Audit::log('account.update_password', 'user', (int) $user['id']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Flash::success('密码已更新。');
        return Response::redirect('/account?tab=security');
    }

    public function activateTotp(Request $request): Response
    {
        Csrf::verify($request);
        $user = Auth::user();

        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            Flash::info('二次验证已启用，无需重复操作。');
            return Response::redirect('/account?tab=2fa');
        }

        $secret = $_SESSION['pending_totp_secret'] ?? '';
        $code = (string) $request->raw('code', '');
        $errors = [];

        if (!is_string($secret) || $secret === '') {
            $errors['form'] = '待确认的密钥已失效，请重新获取后再试';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $errors['code'] = '请输入 6 位动态验证码';
        } elseif (!Totp::verifyCode($secret, $code)) {
            $errors['code'] = '验证码不正确，请确认身份验证器时间准确';
        }

        if ($errors !== []) {
            return $this->renderTwofactor($user, $errors, 422);
        }

        User::update((int) $user['id'], [
            'totp_secret' => $secret,
            'totp_enabled' => 1,
        ]);
        unset($_SESSION['pending_totp_secret']);
        Audit::log('totp.enable', 'user', (int) $user['id']);
        Flash::success('二次验证已启用。下次登录需要输入身份验证器中的动态码。');
        return Response::redirect('/account?tab=2fa');
    }

    public function disableTotp(Request $request): Response
    {
        Csrf::verify($request);
        $user = Auth::user();

        $secret = (string) ($user['totp_secret'] ?? '');
        if ((int) ($user['totp_enabled'] ?? 0) !== 1 || $secret === '') {
            Flash::info('当前账号尚未启用二次验证。');
            return Response::redirect('/account?tab=2fa');
        }

        $code = (string) $request->raw('code', '');
        $errors = [];
        if (!preg_match('/^\d{6}$/', $code)) {
            $errors['code'] = '请输入 6 位动态验证码';
        } elseif (!Totp::verifyCode($secret, $code)) {
            $errors['code'] = '验证码不正确，请确认身份验证器时间准确';
        }

        if ($errors !== []) {
            return $this->renderTwofactor($user, $errors, 422);
        }

        User::update((int) $user['id'], [
            'totp_secret' => null,
            'totp_enabled' => 0,
        ]);
        unset($_SESSION['pending_totp_secret']);
        Audit::log('totp.disable', 'user', (int) $user['id']);
        Flash::success('二次验证已停用。此后登录只需密码。');
        return Response::redirect('/account?tab=2fa');
    }

    public function requestData(Request $request): Response
    {
        Csrf::verify($request);
        $user = Auth::user();

        $type = (string) $request->input('type', '');
        $note = trim((string) $request->input('note', ''));
        $memorialId = $request->int('memorial_id') ?: null;
        $errors = [];

        if (!in_array($type, ['export', 'delete', 'revoke'], true)) {
            $errors['type'] = '请选择申请类型';
        }
        if (mb_strlen($note) > 1000) {
            $errors['note'] = '说明不能超过 1000 字';
        }
        if ($memorialId !== null) {
            $owned = Db::value(
                "SELECT 1 FROM memorial_members WHERE memorial_id = ? AND user_id = ? AND status = 'active' AND role = 'owner' LIMIT 1",
                [$memorialId, (int) $user['id']]
            );
            if (!$owned) {
                $errors['memorial_id'] = '仅馆主可申请导出、删除或撤回该纪念馆的授权';
            }
        }
        if (!RateLimiter::hit('data-request:' . $user['id'], 3, 86400)) {
            $errors['form'] = '提交过于频繁，请明天再试';
        }

        if ($errors !== []) {
            return View::render('account/settings', [
                'title' => '账号设置',
                'user' => $user,
                'requests' => DataRequest::forUser((int) $user['id']),
                'errors' => $errors,
                'tab' => 'privacy',
            ], 'layout', 422);
        }

        $id = DataRequest::create((int) $user['id'], $memorialId, $type, $note);
        Audit::log('data_request.create', 'data_request', $id, ['type' => $type, 'memorial_id' => $memorialId]);
        Flash::success('申请已记录。平台会在核验身份后处理，并通过你留下的联系方式回复。');
        return Response::redirect('/account?tab=privacy');
    }

    private function twofactorViewData(array $user): array
    {
        $enabled = (int) ($user['totp_enabled'] ?? 0) === 1 && (string) ($user['totp_secret'] ?? '') !== '';
        if ($enabled) {
            unset($_SESSION['pending_totp_secret']);
            return [
                'totpEnabled' => true,
                'pendingSecret' => null,
                'otpauthUri' => null,
            ];
        }

        $pending = $_SESSION['pending_totp_secret'] ?? null;
        if (!is_string($pending) || $pending === '') {
            $pending = Totp::generateSecret();
            $_SESSION['pending_totp_secret'] = $pending;
        }

        return [
            'totpEnabled' => false,
            'pendingSecret' => $pending,
            'otpauthUri' => Totp::otpauthUri((string) $user['email'], $pending),
        ];
    }

    private function renderTwofactor(array $user, array $errors, int $status): Response
    {
        return View::render('account/settings', [
            'title' => '账号设置',
            'user' => $user,
            'requests' => DataRequest::forUser((int) $user['id']),
            'errors' => $errors,
            'tab' => '2fa',
            ...$this->twofactorViewData($user),
        ], 'layout', $status);
    }
}
