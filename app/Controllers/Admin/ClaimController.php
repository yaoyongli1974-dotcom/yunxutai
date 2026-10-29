<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Claim;
use App\Models\Member;

final class ClaimController
{
    private const STATUSES = ['open', 'approved', 'rejected'];

    public function index(Request $request): Response
    {
        $this->guard();

        $status = (string) $request->input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        return $this->renderIndex($request, $status);
    }

    public function handle(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $claim = Claim::find((int) $id);
        if ($claim === null) {
            throw new HttpException(404, '申领记录不存在');
        }
        if (($claim['status'] ?? '') !== 'open') {
            Flash::info('该申领已处理，无需重复操作。');
            return Response::redirect('/admin/claims');
        }

        $action = (string) $request->input('action', '');
        $resolution = trim((string) $request->input('resolution', ''));

        $errors = [];
        if (!in_array($action, ['approve', 'reject'], true)) {
            $errors['action'] = '请选择通过或驳回申领';
        }
        if (mb_strlen($resolution) > 500) {
            $errors['resolution'] = '处理说明不能超过 500 字';
        }

        if ($errors !== []) {
            return $this->renderIndex($request, '', $errors, [
                'id' => (int) $claim['id'],
                'action' => $action,
                'resolution' => $resolution,
            ], 422);
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        Db::transaction(static function () use ($claim, $newStatus, $resolution, $admin): void {
            if ($newStatus === 'approved') {
                // MVP 先授予协作者权限，馆主转移需另行线下核验与书面确认。
                $existingRole = Member::roleOf((int) $claim['memorial_id'], (int) $claim['user_id']);
                if ($existingRole === null || $existingRole === 'viewer') {
                    Member::upsert((int) $claim['memorial_id'], (int) $claim['user_id'], 'editor', (int) $admin['id']);
                }
                Policy::clearCache();
            }

            Claim::updateStatus((int) $claim['id'], $newStatus, (int) $admin['id'], $resolution !== '' ? $resolution : null);
            Audit::log('admin.claim.' . $newStatus, 'claim', (int) $claim['id'], [
                'memorial_id' => (int) $claim['memorial_id'],
                'user_id' => (int) $claim['user_id'],
                'resolution' => $resolution,
            ]);
        });

        if ($newStatus === 'approved') {
            Flash::success('申领已通过，申请人已被设为该纪念馆的协作者。请继续线下核验身份与授权材料；馆主变更不在本次操作中完成。');
        } else {
            Flash::success('申领已驳回。建议在记录中保留核验结论，便于后续申诉复核。');
        }

        return Response::redirect('/admin/claims');
    }

    private function renderIndex(Request $request, string $status = '', array $errors = [], array $old = [], int $httpStatus = 200): Response
    {
        $total = Claim::count($status !== '' ? $status : null);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/claims', [
            'title' => '申领管理',
            'noindex' => true,
            'active' => 'claims',
            'claims' => Claim::paginate($status !== '' ? $status : null, $paginator->perPage, $paginator->offset),
            'status' => $status,
            'paginator' => $paginator,
            'query' => $status !== '' ? ['status' => $status] : [],
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
