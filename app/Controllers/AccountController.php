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
use App\Core\Validator;
use App\Core\View;
use App\Models\DataRequest;
use App\Models\User;

final class AccountController
{
    public function show(Request $request): Response
    {
        $user = Auth::user();

        return View::render('account/settings', [
            'title' => '账号设置',
            'user' => $user,
            'requests' => DataRequest::forUser((int) $user['id']),
            'errors' => [],
            'tab' => (string) ($request->query['tab'] ?? 'profile'),
        ]);
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
}
