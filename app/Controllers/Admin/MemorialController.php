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
use App\Models\Memorial;
use App\Models\User;
use App\Services\MemorialService;

final class MemorialController
{
    private const STATUSES = ['draft', 'active', 'suspended'];
    private const VISIBILITIES = ['public', 'unlisted', 'private'];

    public function index(Request $request): Response
    {
        $this->guard();

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'visibility' => (string) $request->input('visibility', ''),
        ];
        if (!in_array($filters['status'], self::STATUSES, true)) {
            $filters['status'] = '';
        }
        if (!in_array($filters['visibility'], self::VISIBILITIES, true)) {
            $filters['visibility'] = '';
        }
        if (mb_strlen($filters['q']) > 80) {
            $filters['q'] = mb_substr($filters['q'], 0, 80);
        }

        $total = Memorial::adminCount($filters);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/memorials/index', [
            'title' => '纪念馆管理',
            'noindex' => true,
            'active' => 'memorials',
            'memorials' => Memorial::adminPaginate($filters, $paginator->perPage, $paginator->offset),
            'filters' => $filters,
            'paginator' => $paginator,
            'query' => array_filter($filters, static fn (string $value): bool => $value !== ''),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->guard();
        return $this->renderShow((int) $id);
    }

    public function suspend(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $memorial = $this->findMemorial((int) $id);
        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            return $this->renderShow((int) $id, [
                'reason' => '暂停理由需为 5–500 字，并说明已核实的事实依据',
            ], ['reason' => $reason], 422);
        }

        MemorialService::suspendByAdmin($memorial, $reason, (int) $admin['id']);
        Audit::log('admin.memorial.suspend', 'memorial', (int) $memorial['id'], ['reason' => $reason]);

        Flash::success('纪念馆已暂停展示，公开页面将不可访问。');
        return Response::redirect('/admin/memorials/' . (int) $memorial['id']);
    }

    public function restore(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $memorial = $this->findMemorial((int) $id);
        Memorial::update((int) $memorial['id'], [
            'status' => 'active',
            'suspended_reason' => null,
            'suspended_by' => null,
        ]);
        Audit::log('admin.memorial.restore', 'memorial', (int) $memorial['id'], [
            'admin_id' => (int) $admin['id'],
        ]);

        Flash::success('纪念馆已恢复展示。');
        return Response::redirect('/admin/memorials/' . (int) $memorial['id']);
    }

    private function renderShow(int $id, array $errors = [], array $old = [], int $status = 200): Response
    {
        $memorial = $this->findMemorial($id);

        $recentMessages = Db::fetchAll(
            'SELECT * FROM messages WHERE memorial_id = ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [$id]
        );
        $recentArticles = Db::fetchAll(
            'SELECT * FROM articles WHERE memorial_id = ? ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 5',
            [$id]
        );
        $recentReports = Db::fetchAll(
            'SELECT r.*, u.display_name AS reporter_display
             FROM reports r LEFT JOIN users u ON u.id = r.reporter_user_id
             WHERE r.memorial_id = ? ORDER BY r.created_at DESC, id DESC LIMIT 5',
            [$id]
        );

        return View::render('admin/memorials/show', [
            'title' => '纪念馆 · ' . $memorial['title'],
            'noindex' => true,
            'active' => 'memorials',
            'memorial' => $memorial,
            'creator' => User::find((int) $memorial['created_by']),
            'counts' => Memorial::counts($id),
            'recentMessages' => $recentMessages,
            'recentArticles' => $recentArticles,
            'recentReports' => $recentReports,
            'errors' => $errors,
            'old' => $old,
        ], 'layout', $status);
    }

    private function findMemorial(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        return $memorial;
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
