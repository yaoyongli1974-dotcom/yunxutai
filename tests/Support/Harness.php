<?php declare(strict_types=1);

use App\Core\Db;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $failures;
    try {
        $fn();
        $passed++;
        echo "  ✓ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        echo "  ✗ {$name}\n      " . $e->getMessage() . "\n";
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function check_contains(string $haystack, string $needle, string $message = ''): void
{
    check(str_contains($haystack, $needle), $message !== '' ? $message : '缺少内容：' . $needle);
}

function check_not_contains(string $haystack, string $needle, string $message = ''): void
{
    check(!str_contains($haystack, $needle), $message !== '' ? $message : '不应出现：' . $needle);
}

/**
 * API 请求助手：JSON body + Bearer token，返回解析后的数组。
 * 使用独立 cookie jar，避免继承 web 测试的会话状态（API 客户端应只依赖 Bearer token）。
 * @param array<string,mixed>|null $json
 * @return array{status:int,data:mixed,raw:string}
 */
function api(string $method, string $path, ?array $json = null, ?string $token = null): array
{
    global $baseUrl;
    static $apiCookieJar = null;
    $apiCookieJar ??= sys_get_temp_dir() . '/jingsi-test-api-cookies-' . getmypid() . '.txt';

    $command = [
        'curl', '-sS', '--max-time', '25',
        '-b', $apiCookieJar, '-c', $apiCookieJar,
        '-X', $method,
        '-H', 'Accept: application/json',
        '-H', 'Content-Type: application/json',
        '-w', "\n__STATUS__%{http_code}",
    ];
    if ($token !== null) {
        $command[] = '-H';
        $command[] = 'Authorization: Bearer ' . $token;
    }
    if ($json !== null) {
        $command[] = '--data';
        $command[] = json_encode($json, JSON_UNESCAPED_UNICODE);
    }
    $command[] = $baseUrl . $path;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "无法启动 curl\n");
        exit(1);
    }
    $body = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($stderr !== '' && trim($stderr) !== '') {
        fwrite(STDERR, 'curl: ' . trim($stderr) . "\n");
    }
    if (!preg_match('/__STATUS__(\d+)\s*$/', (string) $body, $m)) {
        return ['status' => 0, 'data' => null, 'raw' => (string) $body];
    }
    $raw = trim(substr((string) $body, 0, -strlen($m[0])));
    $decoded = json_decode($raw, true);
    return ['status' => (int) $m[1], 'data' => $decoded, 'raw' => $raw];
}

/** 从 API 响应中提取 Bearer token */
function api_token_of(array $response): string
{
    return (string) ($response['data']['data']['token'] ?? '');
}

/**
 * @param array<string, string>|null $form
 * @param array<string, string> $files
 * @return array{status:int,body:string,headers:string,location:string,url:string}
 */
function http(string $method, string $path, ?array $form = null, array $files = [], bool $follow = true): array
{
    global $baseUrl, $cookieJar;

    $headerFile = sys_get_temp_dir() . '/jingsi-test-headers-' . getmypid() . '.txt';
    $command = [
        'curl', '-sS', '--max-time', '25',
        '-b', $cookieJar, '-c', $cookieJar,
        '-D', $headerFile,
        '-o', '-',
        '-w', "\n__STATUS__%{http_code}\n__URL__%{url_effective}",
    ];
    if ($follow) {
        $command[] = '-L';
    }

    if ($files !== []) {
        foreach ($form ?? [] as $key => $value) {
            $command[] = '-F';
            $command[] = $key . '=' . $value;
        }
        foreach ($files as $field => $file) {
            $command[] = '-F';
            $command[] = $field . '=@' . $file;
        }
    } elseif ($form !== null) {
        foreach ($form as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $command[] = '--data-urlencode';
                    $command[] = $key . '[]=' . $item;
                }
                continue;
            }
            $command[] = '--data-urlencode';
            $command[] = $key . '=' . $value;
        }
    }

    $command[] = $baseUrl . $path;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法执行 curl');
    }
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('curl 失败：' . $error);
    }

    $status = 0;
    $effectiveUrl = '';
    if (preg_match('/\n__STATUS__(\d{3})\n__URL__(.+)$/s', $output, $matches)) {
        $status = (int) $matches[1];
        $effectiveUrl = trim($matches[2]);
        $output = substr($output, 0, -strlen($matches[0]));
    }
    $headers = is_file($headerFile) ? (string) file_get_contents($headerFile) : '';
    @unlink($headerFile);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches)) {
        $location = trim($matches[1]);
    }

    return ['status' => $status, 'body' => $output, 'headers' => $headers, 'location' => $location, 'url' => $effectiveUrl];
}

function effective_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    return is_string($path) ? $path : '/';
}

function csrf_token(string $path): string
{
    $response = http('GET', $path);
    check($response['status'] === 200, "页面 {$path} 返回 " . $response['status']);
    if (!preg_match('/name="_token"\s+value="([a-f0-9]{32,})"/', $response['body'], $matches)) {
        throw new RuntimeException("页面 {$path} 未找到 CSRF token");
    }
    return $matches[1];
}

function reset_session(): void
{
    global $cookieJar;
    @unlink($cookieJar);
}

function login(string $email, string $password): void
{
    reset_session();
    $token = csrf_token('/login');
    $response = http('POST', '/login', [
        '_token' => $token,
        'email' => $email,
        'password' => $password,
        'next' => '/dashboard',
    ]);
    check($response['status'] === 200, '登录后未进入页面，状态 ' . $response['status']);
    check(effective_path($response['url']) === '/dashboard', '登录失败，停留在 ' . effective_path($response['url']));
}

function logout(): void
{
    $token = csrf_token('/dashboard');
    http('POST', '/logout', ['_token' => $token]);
    reset_session();
}

function db_count(string $table, string $where = '1 = 1', array $params = []): int
{
    return (int) Db::value("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
}
