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
use App\Models\ServiceRequest;

final class ServiceController
{
    private const STATUSES = ['new', 'contacted', 'closed'];

    public function index(Request $request): Response
    {
        $this->guard();

        $status = (string) $request->input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        return $this->renderIndex($request, $status);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $serviceRequest = Db::fetch('SELECT * FROM service_requests WHERE id = ? LIMIT 1', [(int) $id]);
        if ($serviceRequest === null) {
            throw new HttpException(404, '服务申请不存在');
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            return $this->renderIndex($request, '', [
                'status' => '状态只能设为新申请、已联系或已关闭',
            ], ['id' => (int) $serviceRequest['id'], 'status' => $status], 422);
        }

        ServiceRequest::setStatus((int) $serviceRequest['id'], $status, (int) $admin['id']);
        Audit::log('admin.service_request.update', 'service_request', (int) $serviceRequest['id'], [
            'status' => $status,
            'service_type' => (string) $serviceRequest['service_type'],
        ]);

        Flash::success('服务申请状态已更新。');
        return Response::redirect('/admin/services');
    }

    private function renderIndex(Request $request, string $status = '', array $errors = [], array $old = [], int $httpStatus = 200): Response
    {
        $total = ServiceRequest::count($status !== '' ? $status : null);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/services', [
            'title' => '服务申请',
            'noindex' => true,
            'active' => 'services',
            'requests' => ServiceRequest::paginate($status !== '' ? $status : null, $paginator->perPage, $paginator->offset),
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
