<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\ServiceRequest;
use App\Models\AiService;

final class PageController
{
    public function about(Request $request): Response
    {
        return View::render('pages/about', ['title' => '关于我们']);
    }

    public function privacy(Request $request): Response
    {
        return View::render('pages/privacy', ['title' => '隐私与数据保护']);
    }

    public function terms(Request $request): Response
    {
        return View::render('pages/terms', ['title' => '服务条款']);
    }

    public function services(Request $request): Response
    {
        return View::render('pages/services', [
            'title' => '长期纪念服务',
            'old' => [],
            'errors' => [],
            'aiServices' => AiService::all(true),
        ]);
    }

    public function submitService(Request $request): Response
    {
        Csrf::verify($request);

        $data = [
            'name' => trim((string) $request->input('name', '')),
            'contact' => trim((string) $request->input('contact', '')),
            'service_type' => (string) $request->input('service_type', ''),
            'message' => trim((string) $request->input('message', '')),
        ];

        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 80) {
            $errors['name'] = '请填写称呼（80 字以内）';
        }
        if (mb_strlen($data['contact']) < 5 || mb_strlen($data['contact']) > 190) {
            $errors['contact'] = '请填写有效的联系方式';
        }
        if (!in_array($data['service_type'], ['photo_restore', 'memorial_video', 'life_archive', 'family_archive', 'institution', 'other'], true)) {
            $errors['service_type'] = '请选择服务类型';
        }
        if (mb_strlen($data['message']) > 2000) {
            $errors['message'] = '说明不能超过 2000 字';
        }
        if (trim((string) $request->input('website', '')) !== '') {
            $errors['form'] = '提交失败，请重试';
        }
        if (!RateLimiter::hit('service:' . $request->ip(), 5, 3600)) {
            $errors['form'] = '提交过于频繁，请稍后再试';
        }

        if ($errors !== []) {
            return View::render('pages/services', [
                'title' => '长期纪念服务',
                'old' => $data,
                'errors' => $errors,
                'aiServices' => AiService::all(true),
            ], 'layout', 422);
        }

        $user = Auth::user();
        $id = ServiceRequest::create(
            $user['id'] ?? null,
            null,
            $data['name'],
            $data['contact'],
            $data['service_type'],
            $data['message']
        );
        Audit::log('service_request.create', 'service_request', $id, ['type' => $data['service_type']]);
        Flash::success('咨询已提交。我们会在工作日与你联系，不会自动扣费。');
        return Response::redirect('/services');
    }
}
