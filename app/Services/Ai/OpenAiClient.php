<?php
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class OpenAiClient implements AiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $textModel,
        private readonly string $imageModel,
        private readonly int $timeout
    ) {
    }

    public function provider(): string
    {
        return 'openai-compatible';
    }

    public function generateText(string $system, string $prompt, int $maxTokens = 1800): string
    {
        $payload = [
            'model' => $this->textModel,
            'temperature' => 0.4,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        $json = $this->postJson('/chat/completions', $payload);
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI 未返回文字内容');
        }
        return trim($content);
    }

    public function restoreImage(string $sourcePath, string $prompt): string
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('待修复照片不存在');
        }
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false) {
            throw new RuntimeException('读取待修复照片失败');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($sourcePath);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $boundary = '----jingsi' . bin2hex(random_bytes(12));
        $fields = [
            'model' => $this->imageModel,
            'prompt' => $prompt,
            'size' => '1024x1024',
        ];
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"source.{$extension}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $bytes . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $json = $this->request('/images/edits', $body, 'multipart/form-data; boundary=' . $boundary);
        $data = $json['data'][0] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('AI 未返回修复图片');
        }
        if (!empty($data['b64_json']) && is_string($data['b64_json'])) {
            $decoded = base64_decode($data['b64_json'], true);
            if ($decoded === false) {
                throw new RuntimeException('AI 返回的图片数据无法解码');
            }
            return $decoded;
        }
        if (!empty($data['url']) && is_string($data['url'])) {
            $image = @file_get_contents($data['url'], false, stream_context_create([
                'http' => ['timeout' => $this->timeout, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]));
            if ($image === false) {
                throw new RuntimeException('下载 AI 图片失败');
            }
            return $image;
        }
        throw new RuntimeException('AI 返回格式无法识别');
    }

    private function postJson(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $this->request($path, $body, 'application/json');
    }

    private function request(string $path, string $body, string $contentType): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$this->apiKey}\r\n"
                    . "Content-Type: {$contentType}\r\n"
                    . "Accept: application/json\r\n"
                    . "User-Agent: JingsiYuan/1.0\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $error = error_get_last()['message'] ?? '连接失败';
            throw new RuntimeException('AI 服务请求失败：' . $error);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI 服务返回了无法解析的内容（HTTP ' . $status . '）');
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? ('AI 服务错误 HTTP ' . $status);
            throw new RuntimeException(mb_substr((string) $message, 0, 300));
        }
        return $decoded;
    }
}
