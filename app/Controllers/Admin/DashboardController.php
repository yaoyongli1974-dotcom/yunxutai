<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Article;
use App\Models\Claim;
use App\Models\DataRequest;
use App\Models\Message;
use App\Models\Report;
use App\Models\ServiceRequest;
use App\Models\Stats;
use App\Models\Verification;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $this->guard();

        return View::render('admin/dashboard', [
            'title' => '运营概览',
            'noindex' => true,
            'active' => 'dashboard',
            'summary' => Stats::adminSummary(),
            'pendingMessages' => Message::pendingAll(5),
            'pendingArticles' => Article::pending(5, 0),
            'pendingVerifications' => Verification::paginate('pending', 5, 0),
            'openReports' => Report::paginate('open', 5, 0),
            'openClaims' => Claim::paginate('open', 5, 0),
            'newServices' => ServiceRequest::paginate('new', 5, 0),
            'openDataRequests' => DataRequest::paginate('open', 5, 0),
        ]);
    }

    /**
     * 后台路由已有 admin 中间件，这里再做一次服务端校验，避免路由配置变化后越权。
     */
    private function guard(): array
    {
        $admin = Auth::user();
        if ($admin === null || ($admin['role'] ?? '') !== 'admin') {
            throw new HttpException(403, '没有访问权限');
        }
        return $admin;
    }
}
