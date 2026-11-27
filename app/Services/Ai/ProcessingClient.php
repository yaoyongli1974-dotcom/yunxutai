<?php
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

final class ProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly bool $retryable = false
    ) {
        parent::__construct($message);
    }
}

/**
 * OCR / 语音转写 Provider 适配层（Round 2 D-01~D-04）。
 *
 * 安全约束：只连接显式配置的 HTTPS Provider；DNS 解析后校验全部 A/AAAA 记录并固定连接 IP
 * （防 DNS rebinding）；禁止重定向；限制文件、请求与响应体积；API Key 不写日志。
 */
final class ProcessingClient
{
    private const ALLOWED = [
        'ocr' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/tiff' => ['tif', 'tiff'],
            'application/pdf' => ['pdf'],
        ],
        'asr' => [
            'audio/mpeg' => ['mp3'],
            'audio/wav' => ['wav'],
            'audio/x-wav' => ['wav'],
            'audio/mp4' => ['m4a', 'mp4'],
            'audio/x-m4a' => ['m4a'],
            'audio/ogg' => ['ogg'],
            'audio/webm' => ['webm'],
        ],
    ];

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

    /** @return array{provider:string,model:string,protocol:string} */
    public static function describe(string $kind): array
    {
        return [
            'provider' => (string) config('ai.' . $kind . '.provider', 'custom'),
            'model' => (string) config('ai.' . $kind . '.model', ''),
            'protocol' => (string) config('ai.' . $kind . '.protocol', 'custom_json'),
        ];
    }

    /**
     * SSRF 防护：解析 URL、校验 scheme/userinfo/端口/DNS 全部结果，返回连接目标。
     * 返回的 host/ip/port 供 recognize() 用 CURLOPT_RESOLVE 固定连接。
     *
     * @return array{url:string,host:string,port:int,ip:string}
     */
    public static function validateEndpoint(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ProcessingException('validation_error', 'Provider 地址无效');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $isProduction = (string) config('app.env', 'local') === 'production';
        if ($scheme !== 'https' && !($scheme === 'http' && !$isProduction && self::trustedTestHost((string) $parts['host']))) {
            throw new ProcessingException('validation_error', '仅允许 HTTPS Provider（非生产环境的测试地址除外）');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ProcessingException('validation_error', 'Provider 地址不得包含用户名或密码');
        }
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new ProcessingException('validation_error', 'Provider 端口不被允许');
        }
        if (self::isBlockedHost($host)) {
            throw new ProcessingException('validation_error', 'Provider 主机不被允许');
        }

        $ips = [];
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A + DNS_AAAA) : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        }
        if ($ips === [] && function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
        if ($ips === []) {
            throw new ProcessingException('dns_error', 'Provider 域名解析失败');
        }
        // 所有解析结果都必须安全，避免通过其中一条记录绕过校验。
        $ips = array_values(array_unique($ips));
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new ProcessingException('validation_error', 'Provider 解析到不允许的地址');
            }
        }
        return [
            'url' => rtrim($url, '/'),
            'host' => $host,
            'port' => $port,
            'ip' => $ips[0],
        ];
    }

    public static function recognize(string $kind, string $absolutePath, string $mimeType, string $prompt = ''): string
    {
        self::assertKind($kind);
        if (!self::enabled($kind)) {
            throw new ProcessingException('validation_error', 'OCR/转写服务尚未在管理后台配置');
        }

        $mime = self::validateFile($kind, $absolutePath, $mimeType);
        $maxBytes = (int) config('ai.processing.max_bytes', $kind === 'asr' ? 52428800 : 20971520);
        if ($maxBytes <= 0) {
            $maxBytes = $kind === 'asr' ? 52428800 : 20971520;
        }
        $size = (int) (@filesize($absolutePath) ?: 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new ProcessingException('file_too_large', '待处理文件超过大小限制或为空');
        }

        $base = rtrim((string) config('ai.' . $kind . '.base_url'), '/');
        $suffix = (string) config('ai.' . $kind . '.endpoint_path', $kind === 'ocr' ? '/ocr' : '/transcriptions');
        if (!str_starts_with($suffix, '/') || str_contains($suffix, '..')) {
            throw new ProcessingException('validation_error', 'Provider 路径无效');
        }
        $target = self::validateEndpoint($base . $suffix);

        $bytes = file_get_contents($absolutePath, false, null, 0, $maxBytes + 1);
        if ($bytes === false || strlen($bytes) > $maxBytes) {
            throw new ProcessingException('file_too_large', '待处理文件读取失败或超过大小限制');
        }
        $encoded = base64_encode($bytes);
        $protocol = (string) config('ai.' . $kind . '.protocol', 'custom_json');
        $payload = self::buildPayload($protocol, $kind, $mime, $encoded, $prompt);
        if ($payload === false) {
            throw new ProcessingException('invalid_response', '请求内容编码失败');
        }

        $ch = curl_init($target['url']);
        if ($ch === false) {
            throw new ProcessingException('network_error', '无法初始化 Provider 请求', true);
        }
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string) config('ai.' . $kind . '.api_key'),
        ];
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(5, min(120, (int) config('ai.timeout', 120))),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($maxBytes): int {
                $len = strlen($chunk);
                static $total = 0;
                $total += $len;
                return $total > $maxBytes ? 0 : $len;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = 0;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            $category = self::networkCategory($error);
            throw new ProcessingException($category, 'Provider 请求失败：' . mb_substr($error, 0, 120), in_array($category, ['network_error', 'dns_error', 'tls_error'], true));
        }
        if (in_array($status, [401, 403, 422], true)) {
            throw new ProcessingException('auth_error', 'Provider 拒绝请求（' . $status . '）');
        }
        if ($status === 429) {
            throw new ProcessingException('rate_limited', 'Provider 限流（429）', true);
        }
        if ($status < 200 || $status >= 300) {
            throw new ProcessingException('server_error', 'Provider 返回异常状态（' . $status . '）', $status >= 500);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new ProcessingException('invalid_response', 'Provider 返回内容不是合法 JSON');
        }
        $text = $decoded['text'] ?? $decoded['result'] ?? $decoded['data']['text'] ?? null;
        if (!is_string($text) && isset($decoded['choices'][0]['message']['content'])) {
            $text = $decoded['choices'][0]['message']['content'];
        }
        if (!is_string($text) || trim($text) === '') {
            throw new ProcessingException('empty_content', 'Provider 未返回可识别文本');
        }
        return mb_substr(trim($text), 0, 50000);
    }

    public static function isRetryable(Throwable $e): bool
    {
        return $e instanceof ProcessingException && $e->retryable;
    }

    private static function buildPayload(string $protocol, string $kind, string $mime, string $encoded, string $prompt): string|false
    {
        $model = (string) config('ai.' . $kind . '.model', '');
        if ($protocol === 'openai_compatible') {
            return json_encode([
                'model' => $model,
                'mime_type' => $mime,
                'file' => $encoded,
                'prompt' => $prompt,
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($protocol === 'provider_specific') {
            $template = (string) config('ai.' . $kind . '.payload_template', '');
            if ($template === '') {
                return false;
            }
            $rendered = strtr($template, [
                '{model}' => $model,
                '{mime_type}' => $mime,
                '{file_base64}' => $encoded,
                '{prompt}' => $prompt,
            ]);
            return json_encode(['model' => $model, 'payload' => $rendered], JSON_UNESCAPED_UNICODE);
        }
        return json_encode([
            'model' => $model,
            'mime_type' => $mime,
            'file_base64' => $encoded,
            'prompt' => $prompt,
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function validateFile(string $kind, string $absolutePath, string $declaredMime): string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new ProcessingException('unsupported_file', '待处理文件不存在或不可读');
        }
        $real = realpath($absolutePath);
        $root = dirname(__DIR__, 2) . '/storage';
        $tmp = sys_get_temp_dir();
        if ($real === false || !(str_starts_with($real, $root) || str_starts_with($real, $tmp))) {
            throw new ProcessingException('unsupported_file', '待处理文件不在允许的存储目录');
        }
        $detected = function_exists('finfo_file')
            ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $real)
            : (string) mime_content_type($real);
        if (!isset(self::ALLOWED[$kind][$detected])) {
            throw new ProcessingException('unsupported_file', '文件类型不支持 OCR/转写');
        }
        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED[$kind][$detected], true)) {
            throw new ProcessingException('unsupported_file', '文件扩展名与真实类型不匹配');
        }
        // 声明 MIME 仅作提示；真实 MIME 以 finfo 为准。
        if ($declaredMime !== '' && $declaredMime !== $detected && !isset(self::ALLOWED[$kind][$declaredMime])) {
            throw new ProcessingException('unsupported_file', '声明的文件类型不受支持');
        }
        return $detected;
    }

    private static function networkCategory(string $error): string
    {
        $lower = strtolower($error);
        if (str_contains($lower, 'could not resolve') || str_contains($lower, 'name resolution')) {
            return 'dns_error';
        }
        if (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'tls')) {
            return 'tls_error';
        }
        return 'network_error';
    }

    private static function trustedTestHost(string $host): bool
    {
        // 非生产环境仅放行显式声明的测试域名（仍须通过 DNS/IP 安全检查）。
        return in_array($host, ['provider.test', 'localhost.test'], true);
    }

    private static function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isBlockedIp($host);
        }
        foreach (['metadata.google.internal', 'metadata.goog', 'instance-data'] as $name) {
            if ($host === $name || str_ends_with($host, '.' . $name)) {
                return true;
            }
        }
        return false;
    }

    private static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }
        if (strlen($packed) === 4) {
            $a = unpack('C4', $packed);
            if (!is_array($a)) {
                return true;
            }
            [$p1, $p2] = [$a[1], $a[2]];
            if ($p1 === 0 || $p1 === 10 || $p1 === 127 || $p1 === 169 && $p2 === 254
                || $p1 === 172 && $p2 >= 16 && $p2 <= 31
                || $p1 === 192 && ($p2 === 168 || $p2 === 0)
                || $p1 === 100 && $p2 >= 64 && $p2 <= 127
                || $p1 === 198 && ($p2 === 18 || $p2 === 19)) {
                return true;
            }
            return false;
        }
        // IPv6：ULA fc00::/7、link-local fe80::/10、loopback、未指定、IPv4 映射地址。
        $first = ord($packed[0]);
        if ($packed === str_repeat("\0", 16)
            || ($first & 0xfe) === 0xfc
            || ($first & 0xc0) === 0x80
            || substr($packed, 0, 15) === str_repeat("\0", 15) && ord($packed[15]) === 1) {
            return true;
        }
        return str_starts_with($ip, '::ffff:') && self::isBlockedIp(substr($ip, 7));
    }

    private static function assertKind(string $kind): void
    {
        if (!in_array($kind, ['ocr', 'asr'], true)) {
            throw new RuntimeException('未知的处理类型：' . $kind);
        }
    }
}
