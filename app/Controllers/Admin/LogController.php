<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AuditLog;

final class LogController
{
    public function index(Request $request): Response
    {
        $this->guard();

        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 80) {
            $query = mb_substr($query, 0, 80);
        }

        $total = AuditLog::count($query);
        $paginator = Paginator::fromRequest($request, $total, 30);
        $logs = AuditLog::paginate($query, $paginator->perPage, $paginator->offset);

        foreach ($logs as &$log) {
            $meta = json_decode((string) ($log['meta'] ?? ''), true);
            $parts = [];
            if (is_array($meta)) {
                foreach ($meta as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                    } else {
                        $parts[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }
            $log['meta_summary'] = implode(' · ', $parts);
            $log['ip_short'] = substr((string) ($log['ip_hash'] ?? ''), 0, 12);
        }
        unset($log);

        return View::render('admin/logs', [
            'title' => '操作日志',
            'noindex' => true,
            'active' => 'logs',
            'logs' => $logs,
            'query' => $query,
            'paginator' => $paginator,
            'baseQuery' => $query !== '' ? ['q' => $query] : [],
        ]);
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
