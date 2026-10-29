<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AiJob;
use App\Models\AiService;
use App\Models\Media;
use App\Models\Memorial;
use App\Services\Ai\AiClientFactory;
use App\Services\AiStudioService;

final class AiController
{
    public function index(Request $request, string $id): Response
    {
        $memorial = $this->memorial((int) $id);
        $this->guard($memorial);
        $services = AiService::all(true);

        return View::render('manage/ai', [
            'title' => 'AI 工作室 · ' . $memorial['title'],
            'noindex' => true,
            'active' => 'ai',
            'memorial' => $memorial,
            'services' => $services,
            'jobs' => AiJob::forMemorial((int) $memorial['id'], 12),
            'photos' => Media::photos((int) $memorial['id'], 30, true),
            'enabled' => AiClientFactory::enabled(),
            'mode' => AiClientFactory::label(),
            'limit' => (int) config('ai.daily_limit', 10),
        ]);
    }

    public function store(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $memorial = $this->memorial((int) $id);
        $user = $this->guard($memorial);

        if (!AiClientFactory::enabled()) {
            Flash::error('AI 工作室尚未启用，请联系平台运营。');
            return Response::redirect($this->base($memorial) . '/ai');
        }
        if (!$request->bool('consent')) {
            Flash::error('请先确认你有权使用这些照片与资料，并理解内容会发送至 AI 服务处理。');
            return Response::redirect($this->base($memorial) . '/ai');
        }

        $slug = (string) $request->input('service', '');
        $service = AiService::findBySlug($slug);
        if ($service === null || !(int) $service['enabled']) {
            Flash::error('该 AI 服务暂未开放。');
            return Response::redirect($this->base($memorial) . '/ai');
        }

        $limit = max(1, (int) config('ai.daily_limit', 10));
        if (!RateLimiter::hit('ai:' . (int) $user['id'] . ':' . (int) $memorial['id'], $limit, 86400)) {
            Flash::error('今天的 AI 生成次数已用完，请明天再试，或联系平台处理。');
            return Response::redirect($this->base($memorial) . '/ai');
        }

        $input = ['notes' => mb_substr(trim((string) $request->input('notes', '')), 0, 1200)];
        try {
            match ($slug) {
                'photo_restore' => $input += [
                    'media_id' => $this->photoId($request, $memorial),
                    'caption' => mb_substr(trim((string) $request->input('caption', '')), 0, 255),
                ],
                'memorial_video' => $input += [
                    'media_ids' => $this->photoIds($request, $memorial),
                ],
                'life_article' => $input += [
                    'title' => mb_substr(trim((string) $request->input('title', '')), 0, 190),
                ],
                'family_archive' => $input += [],
                default => throw new HttpException(422, '未知的 AI 服务'),
            };
        } catch (HttpException $e) {
            Flash::error($e->getMessage());
            return Response::redirect($this->base($memorial) . '/ai');
        }

        $jobId = AiJob::create(
            (int) $memorial['id'],
            (int) $user['id'],
            $slug,
            $input,
            (int) $service['price_cents']
        );
        Audit::log('ai.job_created', 'ai_job', $jobId, [
            'service' => $slug,
            'memorial_id' => (int) $memorial['id'],
        ]);

        if ((bool) config('ai.sync', true)) {
            try {
                AiStudioService::process($jobId);
                Flash::success('已完成，请核对结果后再决定是否使用。');
            } catch (\Throwable $e) {
                Flash::error('生成未完成：' . $e->getMessage());
            }
        } else {
            Flash::info('任务已进入队列，稍后运行 AI 队列处理。');
        }

        return Response::redirect($this->base($memorial) . '/ai/jobs/' . $jobId);
    }

    public function showJob(Request $request, string $id, string $jobId): Response
    {
        $memorial = $this->memorial((int) $id);
        $this->guard($memorial);
        $job = AiJob::find((int) $jobId);
        if ($job === null || (int) $job['memorial_id'] !== (int) $memorial['id']) {
            throw new HttpException(404, 'AI 任务不存在');
        }
        $media = $job['media_id'] ? Media::find((int) $job['media_id']) : null;
        $article = $job['article_id'] ? \App\Models\Article::find((int) $job['article_id']) : null;

        return View::render('manage/ai-job', [
            'title' => 'AI 任务 #' . (int) $job['id'],
            'noindex' => true,
            'active' => 'ai',
            'memorial' => $memorial,
            'job' => $job,
            'media' => $media,
            'article' => $article,
            'service' => AiService::findBySlug((string) $job['service_slug']),
            'mode' => AiClientFactory::label(),
        ]);
    }

    public function retry(Request $request, string $id, string $jobId): Response
    {
        Csrf::verify($request);
        $memorial = $this->memorial((int) $id);
        $this->guard($memorial);
        $job = AiJob::find((int) $jobId);
        if ($job === null || (int) $job['memorial_id'] !== (int) $memorial['id']) {
            throw new HttpException(404, 'AI 任务不存在');
        }
        if ($job['status'] !== 'failed') {
            Flash::error('只有失败的任务可以重试。');
            return Response::redirect($this->base($memorial) . '/ai/jobs/' . (int) $job['id']);
        }
        AiJob::retry((int) $job['id']);
        if ((bool) config('ai.sync', true)) {
            try {
                AiStudioService::process((int) $job['id']);
                Flash::success('重试完成，请核对结果。');
            } catch (\Throwable $e) {
                Flash::error('重试失败：' . $e->getMessage());
            }
        } else {
            Flash::info('已重新排队。');
        }
        return Response::redirect($this->base($memorial) . '/ai/jobs/' . (int) $job['id']);
    }

    private function memorial(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        return $memorial;
    }

    private function guard(array $memorial): array
    {
        $user = Auth::user();
        if (!Policy::canManage($memorial, $user)) {
            throw new HttpException(403, '只有馆主可以使用 AI 工作室');
        }
        return $user;
    }

    private function base(array $memorial): string
    {
        return '/manage/memorials/' . (int) $memorial['id'];
    }

    private function photoId(Request $request, array $memorial): int
    {
        $mediaId = $request->int('media_id');
        $media = Media::find($mediaId);
        if ($media === null || (int) $media['memorial_id'] !== (int) $memorial['id'] || $media['kind'] !== 'photo' || $media['status'] !== 'active') {
            throw new HttpException(422, '请选择该纪念馆中的有效照片');
        }
        return $mediaId;
    }

    private function photoIds(Request $request, array $memorial): array
    {
        $raw = $request->input('media_ids', []);
        $ids = is_array($raw) ? array_values(array_unique(array_map('intval', $raw))) : [];
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || count($ids) > 8) {
            throw new HttpException(422, '请选择 1–8 张照片');
        }
        foreach ($ids as $mediaId) {
            $media = Media::find($mediaId);
            if ($media === null || (int) $media['memorial_id'] !== (int) $memorial['id'] || $media['kind'] !== 'photo' || $media['status'] !== 'active') {
                throw new HttpException(422, '所选照片中有无效项，请刷新后重试');
            }
        }
        return $ids;
    }
}
