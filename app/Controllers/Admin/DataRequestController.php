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
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\DataRequest;

final class DataRequestController
{
    private const STATUSES = ['open', 'processing', 'done', 'rejected'];
    private const HANDLE_STATUSES = ['processing', 'done', 'rejected'];

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

        $dataRequest = Db::fetch('SELECT * FROM data_requests WHERE id = ? LIMIT 1', [(int) $id]);
        if ($dataRequest === null) {
            throw new HttpException(404, '数据申请不存在');
        }

        $status = (string) $request->input('status', '');
        $resolution = trim((string) $request->input('resolution', ''));

        $errors = [];
        if (!in_array($status, self::HANDLE_STATUSES, true)) {
            $errors['status'] = '状态只能设为处理中、已完成或已驳回';
        }
        if (mb_strlen($resolution) > 500) {
            $errors['resolution'] = '处理说明不能超过 500 字';
        }

        if ($errors !== []) {
            return $this->renderIndex($request, '', $errors, [
                'id' => (int) $dataRequest['id'],
                'status' => $status,
                'resolution' => $resolution,
            ], 422);
        }

        DataRequest::updateStatus((int) $dataRequest['id'], $status, (int) $admin['id'], $resolution !== '' ? $resolution : null);
        Audit::log('admin.data_request.update', 'data_request', (int) $dataRequest['id'], [
            'type' => (string) $dataRequest['type'],
            'status' => $status,
            'user_id' => (int) $dataRequest['user_id'],
            'resolution' => $resolution,
        ]);

        Flash::info('处理状态已登记。数据导出、删除或撤回授权需核验身份与授权后离线执行；本次操作不会删除任何数据。');
        return Response::redirect('/admin/data-requests');
    }

    private function renderIndex(Request $request, string $status = '', array $errors = [], array $old = [], int $httpStatus = 200): Response
    {
        $total = DataRequest::count($status !== '' ? $status : null);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/data-requests', [
            'title' => '数据申请',
            'noindex' => true,
            'active' => 'data-requests',
            'requests' => DataRequest::paginate($status !== '' ? $status : null, $paginator->perPage, $paginator->offset),
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
