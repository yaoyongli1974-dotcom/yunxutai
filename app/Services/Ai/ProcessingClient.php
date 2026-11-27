<?php
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * OCR / 语音转写 Provider 适配层。
 *
 * 管理方式：后台「AI 大模型设置」的 OCR / 转写独立组（ai.ocr.* / ai.asr.*）。
 * 约定：POST {base_url}/ocr 或 {base_url}/transcriptions，JSON:
 *   {model, mime_type, file_base64, prompt}
 * Header: Authorization: Bearer <api_key>
 * 响应支持 {text} / {result} / {data:{text}} / OpenAI choices[0].message.content。
 * 未配置或调用失败时不回退到"凭空生成"，由调用方保持本地草稿或标记失败。
 */
final class ProcessingClient
{
    public static function enabled(string $kind): bool
    {
        self::assertKind($kind);
        return (bool) config('ai.' . $kind . '.enabled', false)
            && (string) config('ai.' . $kind . '.base_url', '') !== ''
            && (string) config('ai.' . $kind . '.api_key', '') !== '';
    }

    public static function label(string $kind): string
    {
        return self::enabled($kind) ? (string) config('ai.' . $kind . '.provider', 'custom') : '本地演示';
    }

    /** @return array{provider:string,model:string} */
    public static function describe(string $kind): array
    {
        return [
            'provider' => (string) config('ai.' . $kind . '.provider', 'custom'),
            'model' => (string) config('ai.' . $kind . '.model', ''),
        ];
    }

    public static function recognize(string $kind, string $absolutePath, string $mimeType, string $prompt = ''): string
    {
        self::assertKind($kind);
        if (!self::enabled($kind)) {
            throw new RuntimeException('OCR/转写服务尚未在管理后台配置');
        }
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('待处理文件不存在或不可读');
        }
        $bytes = file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new RuntimeException('待处理文件读取失败');
        }
        $base = rtrim((string) config('ai.' . $kind . '.base_url'), '/');
        $endpoint = $base . ($kind === 'ocr' ? '/ocr' : '/transcriptions');
        $payload = json_encode([
            'model' => (string) config('ai.' . $kind . '.model', ''),
            'mime_type' => $mimeType,
            'file_base64' => base64_encode($bytes),
            'prompt' => $prompt,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('请求内容编码失败');
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('无法初始化 Provider 请求');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) config('ai.timeout', 120),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string) config('ai.' . $kind . '.api_key'),
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Provider 请求失败（' . $status . '）' . mb_substr($error, 0, 120));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Provider 返回内容不是合法 JSON');
        }
        $text = $decoded['text'] ?? $decoded['result'] ?? $decoded['data']['text'] ?? null;
        if (!is_string($text) && isset($decoded['choices'][0]['message']['content'])) {
            $text = $decoded['choices'][0]['message']['content'];
        }
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Provider 未返回可识别文本');
        }
        return mb_substr(trim($text), 0, 50000);
    }

    private static function assertKind(string $kind): void
    {
        if (!in_array($kind, ['ocr', 'asr'], true)) {
            throw new RuntimeException('未知的处理类型：' . $kind);
        }
    }
}
