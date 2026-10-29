<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AiJob;
use App\Models\AiService;

final class AiServiceController
{
    public function index(Request $request): Response
    {
        $this->guard();

        return View::render('admin/ai-services', [
            'title' => 'AI 服务与价格',
            'noindex' => true,
            'active' => 'ai-services',
            'services' => AiService::all(),
            'jobsTotal' => AiJob::count(),
            'jobsFailed' => AiJob::count('failed'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $this->guard();
        $service = AiService::find((int) $id);
        if ($service === null) {
            throw new HttpException(404, '服务不存在');
        }

        $yuan = trim((string) $request->input('price_yuan', ''));
        $note = trim((string) $request->input('price_note', ''));
        $errors = [];
        if ($yuan === '' || !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $yuan)) {
            $errors['price_yuan'] = '请填写 0–999999 元之间的金额，最多两位小数';
        }
        if (mb_strlen($note) > 190) {
            $errors['price_note'] = '价格说明不能超过 190 字';
        }

        if ($errors !== []) {
            return View::render('admin/ai-services', [
                'title' => 'AI 服务与价格',
                'noindex' => true,
                'active' => 'ai-services',
                'services' => AiService::all(),
                'jobsTotal' => AiJob::count(),
                'jobsFailed' => AiJob::count('failed'),
                'errors' => $errors,
                'editing' => $service,
                'old' => $request->post,
            ], 'layout', 422);
        }

        $cents = (int) round(((float) $yuan) * 100);
        AiService::update((int) $service['id'], [
            'price_cents' => $cents,
            'price_note' => $note,
            'enabled' => $request->bool('enabled'),
        ]);
        Audit::log('admin.ai_service_updated', 'ai_service', (int) $service['id'], [
            'slug' => (string) $service['slug'],
            'price_cents' => $cents,
            'enabled' => $request->bool('enabled'),
        ]);
        Flash::success('已更新「' . $service['name'] . '」的展示价格与开放状态。当前不接入支付，价格仅用于展示与运营记录。');
        return Response::redirect('/admin/ai-services');
    }

    private function guard(): void
    {
        if (!Auth::isAdmin()) {
            throw new HttpException(403, '无权访问');
        }
    }
}
