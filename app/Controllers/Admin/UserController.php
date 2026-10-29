<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\User;

final class UserController
{
    public function index(Request $request): Response
    {
        $this->guard();

        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 80) {
            $query = mb_substr($query, 0, 80);
        }

        return $this->renderIndex($request, $query);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $target = User::find((int) $id);
        if ($target === null) {
            throw new HttpException(404, '用户不存在');
        }

        $action = (string) $request->input('action', '');
        $errors = [];
        if (!in_array($action, ['enable', 'disable'], true)) {
            $errors['form'] = '请选择启用或禁用操作';
        }
        if ($action === 'disable' && (int) $target['id'] === (int) $admin['id']) {
            $errors['form'] = '不能禁用当前登录的管理员账号';
        }

        if ($errors !== []) {
            return $this->renderIndex($request, '', $errors, [
                'id' => (int) $target['id'],
                'action' => $action,
            ], 422);
        }

        $status = $action === 'enable' ? 'active' : 'disabled';
        // 后台不允许在此处修改角色，角色变更需通过独立的授权流程。
        User::update((int) $target['id'], ['status' => $status]);
        Audit::log('admin.user.' . $action, 'user', (int) $target['id'], [
            'status' => $status,
            'email' => (string) $target['email'],
        ]);

        Flash::success($action === 'enable' ? '账号已启用。' : '账号已禁用，该用户将无法登录。');
        return Response::redirect('/admin/users');
    }

    private function renderIndex(Request $request, string $query = '', array $errors = [], array $old = [], int $httpStatus = 200): Response
    {
        $total = User::searchCount($query);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/users', [
            'title' => '用户管理',
            'noindex' => true,
            'active' => 'users',
            'users' => User::searchPaginate($query, $paginator->perPage, $paginator->offset),
            'query' => $query,
            'paginator' => $paginator,
            'baseQuery' => $query !== '' ? ['q' => $query] : [],
            'errors' => $errors,
            'old' => $old,
        ], 'layout', $httpStatus);
    }

    private function guard(): array
    {
        $admin = Auth::user();
        if ($admin === null || ($admin['role'] ?? '') !== 'admin') {
            throw new HttpException(403, '没有访问权限');
        }
        return $admin;
    }
}
