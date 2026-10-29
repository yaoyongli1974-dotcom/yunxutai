<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AiJob;
use App\Services\AiStudioService;

final class AiJobController
{
    public function index(Request $request): Response
    {
        $this->guard();
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['', 'queued', 'processing', 'completed', 'failed'], true)) {
            $status = '';
        }
        $total = AiJob::count($status !== '' ? $status : null);
        $paginator = Paginator::fromRequest($request, $total, 20);
        $jobs = AiJob::paginate($status !== '' ? $status : null, 20, $paginator->offset);

        return View::render('admin/ai-jobs', [
            'title' => 'AI 任务',
            'noindex' => true,
            'active' => 'ai-jobs',
            'jobs' => $jobs,
            'status' => $status,
            'paginator' => $paginator,
            'counts' => [
                'all' => AiJob::count(),
                'queued' => AiJob::count('queued'),
                'failed' => AiJob::count('failed'),
            ],
        ]);
    }

    public function retry(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $this->guard();
        $job = AiJob::find((int) $id);
        if ($job === null) {
            throw new HttpException(404, '任务不存在');
        }
        if (!in_array($job['status'], ['failed', 'queued'], true)) {
            Flash::error('只有失败或排队中的任务可以重试。');
            return Response::redirect('/admin/ai-jobs');
        }

        AiJob::retry((int) $job['id']);
        try {
            AiStudioService::process((int) $job['id']);
            Flash::success('任务已重新处理，请在纪念馆中核对结果。');
        } catch (\Throwable $e) {
            Flash::error('重试失败：' . $e->getMessage());
        }
        return Response::redirect('/admin/ai-jobs');
    }

    private function guard(): void
    {
        if (!Auth::isAdmin()) {
            throw new HttpException(403, '无权访问');
        }
    }
}
