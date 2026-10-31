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
use App\Core\Settings;
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

    /**
     * POST /admin/ai-settings/models — 用已存/生效的 Key 拉取 {base_url}/models。
     * Key 只在服务端使用，不回传浏览器。失败返回 JSON {message}。
     */
    public function models(Request $request): Response
    {
        Csrf::verify($request);
        $this->guard();

        $post = $request->post;
        $rawBase = trim((string) ($post['base_url'] ?? ''));
        $baseUrl = $rawBase !== '' ? rtrim($rawBase, '/') : rtrim((string) config('ai.base_url', ''), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://api.openai.com/v1';
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $baseUrl)) {
            return Response::json(['message' => 'API 基础地址无效，请先填写合法 URL'], 422);
        }

        $key = trim((string) config('ai.api_key', ''));
        $typedKey = trim((string) ($post['api_key'] ?? ''));
        if ($typedKey !== '') {
            $key = $typedKey;
        }
        if ($key === '') {
            return Response::json(['message' => '尚未配置 API Key（或本次表单中填写后未保存）。可先在下方填写 Key 并保存，或直接重试。'], 422);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nAuthorization: Bearer " . $key . "\r\n",
            ],
        ]);
        $raw = @file_get_contents($baseUrl . '/models', false, $context);
        if ($raw === false) {
            return Response::json(['message' => '无法连接到 ' . $baseUrl . '，请检查地址与网络'], 502);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return Response::json(['message' => '服务返回的数据不是 JSON，请确认这是 OpenAI 兼容 API'], 502);
        }
        if (isset($payload['error'])) {
            $message = (string) ($payload['error']['message'] ?? $payload['error']['code'] ?? '接口返回错误');
            return Response::json(['message' => '接口返回错误：' . $message], 502);
        }

        $models = [];
        $data = $payload['data'] ?? $payload['models'] ?? [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
                    $models[] = ['id' => $item['id'], 'owned_by' => $item['owned_by'] ?? null];
                } elseif (is_string($item) && $item !== '') {
                    $models[] = ['id' => $item, 'owned_by' => null];
                }
            }
        }
        usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        if ($models === []) {
            return Response::json(['message' => '接口未返回任何模型 ID（列表为空）'], 502);
        }

        return Response::json(['models' => $models]);
    }

    /**
     * GET /admin/ai-settings — AI 大模型设置（provider/密钥/模型/执行方式）。
     * console 键值存 platform_settings，覆盖 .env 默认。
     */
    public function settings(Request $request): Response
    {
        $this->guard();

        return View::render('admin/ai-settings', array_merge($this->settingsViewData(), [
            'old' => [],
            'errors' => [],
        ]));
    }

    public function saveSettings(Request $request): Response
    {
        Csrf::verify($request);
        $this->guard();

        $post = $request->post;
        $apiKey = trim((string) ($post['api_key'] ?? ''));
        $hadKey = trim((string) Settings::get('ai.api_key', config('ai.api_key', ''))) !== '';
        $clearKey = (bool) ($post['clear_key'] ?? false);

        $errors = [];
        $provider = (string) ($post['provider'] ?? 'demo');
        if (!in_array($provider, ['demo', 'openai'], true)) {
            $errors['provider'] = '运行模式只能是 demo 或 openai';
        }
        $baseUrl = rtrim(trim((string) ($post['base_url'] ?? '')), '/');
        if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors['base_url'] = '基础地址必须是合法 URL（如 https://api.openai.com/v1）';
        }
        if ($provider === 'openai' && $apiKey === '' && !$hadKey && !$clearKey) {
            $errors['api_key'] = 'openai 模式需要 API Key';
        }
        $textModel = trim((string) ($post['text_model'] ?? ''));
        $imageModel = trim((string) ($post['image_model'] ?? ''));
        if ($provider === 'openai') {
            if ($textModel === '') {
                $errors['text_model'] = 'openai 模式需要文本模型名（如 gpt-4.1-mini）';
            }
            if ($imageModel === '') {
                $errors['image_model'] = 'openai 模式需要图像模型名（如 gpt-image-1）';
            }
        }
        if (mb_strlen($textModel) > 80) {
            $errors['text_model'] = '文本模型名不能超过 80 字符';
        }
        if (mb_strlen($imageModel) > 80) {
            $errors['image_model'] = '图像模型名不能超过 80 字符';
        }
        $timeout = (int) ($post['timeout'] ?? 120);
        if ($timeout < 10 || $timeout > 600) {
            $errors['timeout'] = '接口超时需在 10–600 秒之间';
        }
        $dailyLimit = (int) ($post['daily_limit'] ?? 10);
        if ($dailyLimit < 1 || $dailyLimit > 100) {
            $errors['daily_limit'] = '每人每日任务上限需在 1–100 之间';
        }
        $enabled = (bool) ($post['enabled'] ?? false);
        $sync = (bool) ($post['sync'] ?? true);

        if ($errors !== []) {
            return View::render('admin/ai-settings', array_merge($this->settingsViewData(), [
                'errors' => $errors,
                'old' => $post,
            ]), 'layout', 422);
        }

        Settings::set('ai.enabled', $enabled ? '1' : '0');
        Settings::set('ai.provider', $provider);
        if ($baseUrl !== '') {
            Settings::set('ai.base_url', $baseUrl);
        }
        Settings::set('ai.text_model', $textModel !== '' ? $textModel : (string) config('ai.text_model'));
        Settings::set('ai.image_model', $imageModel !== '' ? $imageModel : (string) config('ai.image_model'));
        $videoModel = trim((string) ($post['video_model'] ?? ''));
        if ($videoModel !== '') {
            Settings::set('ai.video_model', $videoModel);
        }
        Settings::set('ai.timeout', (string) $timeout);
        Settings::set('ai.daily_limit', (string) $dailyLimit);
        Settings::set('ai.sync', $sync ? '1' : '0');
        if ($clearKey) {
            Settings::set('ai.api_key', null);
        } elseif ($apiKey !== '') {
            Settings::set('ai.api_key', $apiKey);
        }

        Audit::log('admin.ai_settings_updated', 'platform_settings', null, [
            'provider' => $provider,
            'enabled' => $enabled,
            'sync' => $sync,
            'daily_limit' => $dailyLimit,
            'api_key_changed' => $apiKey !== '' || $clearKey,
        ]);

        Flash::success('AI 设置已保存并即时生效。密钥仅服务端存储，不会出现在页面与审计日志。');
        return Response::redirect('/admin/ai-settings');
    }

    /**
     * @return array<string,mixed> 设置页视图数据
     */
    private function settingsViewData(): array
    {
        return [
            'title' => 'AI 大模型设置',
            'noindex' => true,
            'active' => 'ai-settings',
            'cfg' => [
                'enabled' => (bool) config('ai.enabled', false),
                'provider' => (string) config('ai.provider', 'demo'),
                'base_url' => (string) config('ai.base_url', ''),
                'text_model' => (string) config('ai.text_model', ''),
                'image_model' => (string) config('ai.image_model', ''),
                'video_model' => (string) config('ai.video_model', ''),
                'timeout' => (int) config('ai.timeout', 120),
                'daily_limit' => (int) config('ai.daily_limit', 10),
                'sync' => (bool) config('ai.sync', true),
                'api_key_set' => trim((string) config('ai.api_key', '')) !== '',
                'api_key_tail' => mb_substr(trim((string) config('ai.api_key', '')), -4),
                'api_key_from' => Settings::get('ai.api_key') !== null ? '设置' : '环境变量',
            ],
            'jobsTotal' => AiJob::count(),
            'jobsFailed' => AiJob::count('failed'),
        ];
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
