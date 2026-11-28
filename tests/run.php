<?php
declare(strict_types=1);

/**
 * 轻量集成测试：需要在 bin/test.sh 启动的测试服务器与 jidian_test 数据库下运行。
 * 不依赖 PHPUnit，便于在最小化环境中执行。
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Kv;
use App\Core\Kv\MysqlCounter;
use App\Core\Kv\RedisCounter;
use App\Core\Kv\RedisSessionHandler;
use App\Core\Kv\RespClient;
use App\Core\Notify;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Storage;
use App\Core\Str;
use App\Core\Totp;
use App\Core\Validator;
use App\Models\Article;
use App\Models\Media;
use App\Models\Message;
use App\Models\Memorial;
use App\Models\Report;
use App\Models\Tribute;
use App\Models\Verification;

require BASE_PATH . '/tests/Support/MockRespServer.php';

$baseUrl = rtrim((string) (getenv('BASE_URL') ?: 'http://127.0.0.1:8110'), '/');
$cookieJar = sys_get_temp_dir() . '/jingsi-test-cookies-' . getmypid() . '.txt';
@unlink($cookieJar);

$passed = 0;
$failed = 0;
$failures = [];

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

echo "云叙台 · 集成测试\n";
echo "目标：{$baseUrl}\n\n";

echo "[单元测试]\n";

test('数据库 schema 已加载', function (): void {
    $tables = ['users', 'deceased', 'memorials', 'memorial_members', 'tributes', 'messages', 'media', 'reports', 'claims', 'audit_logs'];
    foreach ($tables as $table) {
        check(
            (int) Db::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) > 0,
            '缺少表 ' . $table
        );
    }
});

test('密码强度校验', function (): void {
    check(Validator::passwordProblem('short') !== null, '短密码应被拒绝');
    check(Validator::passwordProblem('onlyletters') !== null, '无数字应被拒绝');
    check(Validator::passwordProblem('goodpass123') === null, '合规密码应通过');
});

test('slug 生成与校验', function (): void {
    check(Str::validSlug('zhang-mingyuan'), '合法 slug 被拒绝');
    check(!Str::validSlug('张三'), '中文 slug 应被拒绝');
    check(str_starts_with(Str::slugify('张明远'), 'memory-'), '中文应回退为 memory- 前缀');
});

test('纪念馆权限策略', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');
    check($memorial !== null, '演示纪念馆不存在');
    $owner = \App\Models\User::findByEmail('demo@example.com');
    $editor = \App\Models\User::findByEmail('family@example.com');
    $stranger = \App\Models\User::findByEmail('claimant@example.com');

    check(Policy::role($memorial, $owner) === 'owner', '馆主角色识别失败');
    check(Policy::role($memorial, $editor) === 'editor', '协作者角色识别失败');
    check(Policy::role($memorial, $stranger) === null, '非成员不应有角色');
    check(Policy::canView($memorial, null), '公开纪念馆应允许访客查看');
    check(Policy::canEdit($memorial, $editor), '协作者应可编辑');
    check(!Policy::canManage($memorial, $editor), '协作者不应可管理成员');
    check(!Policy::canEdit($memorial, $stranger), '非成员不应可编辑');

    $private = $memorial;
    $private['visibility'] = 'private';
    check(!Policy::canView($private, null), '私密纪念馆不应允许访客');
    check(Policy::canView($private, $owner), '私密纪念馆应允许成员');
});

test('演示数据完整', function (): void {
    check(db_count('tributes') >= 8, '祭奠数据不足');
    check(db_count('messages') >= 4, '留言数据不足');
    check(db_count('media') >= 3, '媒体数据不足');
    check(db_count('reports', "status = 'open'") >= 1, '缺少待处理举报');
});

echo "\n[公开页面]\n";

test('公开页面可访问', function (): void {
    foreach (['/', '/memorials', '/m/zhang-mingyuan', '/m/zhang-mingyuan/gallery', '/m/zhang-mingyuan/life', '/m/zhang-mingyuan/articles', '/m/zhang-mingyuan/tributes', '/m/zhang-mingyuan/messages', '/about', '/privacy', '/terms', '/services', '/login', '/register'] as $path) {
        $response = http('GET', $path);
        check($response['status'] === 200, "{$path} 返回 " . $response['status']);
    }
});

test('纪念馆页包含必要信息与祭奠入口', function (): void {
    $response = http('GET', '/m/zhang-mingyuan');
    check_contains($response['body'], '张明远');
    check_contains($response['body'], '献花');
    check_contains($response['body'], '留言');
    check_contains($response['body'], '举报');
});

test('sitemap 包含公开可收录纪念馆', function (): void {
    $response = http('GET', '/sitemap.xml');
    check($response['status'] === 200, 'sitemap 状态错误');
    check_contains($response['body'], 'zhang-mingyuan');
});

test('sitemap 非空且包含静态公开页', function (): void {
    $response = http('GET', '/sitemap.xml');
    check($response['status'] === 200, 'sitemap 状态错误');
    check_contains($response['body'], '<loc>');
    check_contains($response['body'], '/memorials</loc>');
    check_contains($response['body'], '/about</loc>');
    check(strpos($response['body'], '<urlset') !== false, 'sitemap 根节点缺失');
});

test('robots.txt 可访问并声明 sitemap', function (): void {
    $response = http('GET', '/robots.txt');
    check($response['status'] === 200, 'robots.txt 状态错误，实际 ' . $response['status']);
    check_contains($response['body'], 'User-agent: *');
    check_contains($response['body'], 'Disallow: /admin');
    check_contains($response['body'], 'Sitemap: ');
    check_contains($response['body'], '/sitemap.xml');
});

test('公开页输出 canonical 链接', function (): void {
    $base = rtrim((string) getenv('BASE_URL'), '/');
    $home = http('GET', '/');
    check_contains($home['body'], '<link rel="canonical" href="' . $base . '/">');
    $about = http('GET', '/about');
    check_contains($about['body'], '<link rel="canonical" href="' . $base . '/about">');
});

test('首页标题与分享元信息完整', function (): void {
    $home = http('GET', '/');
    check_contains($home['body'], '<title>云叙台 · 网上纪念馆｜为逝去的亲人建一座纪念馆</title>');
    check_contains($home['body'], 'property="og:title"');
    check_contains($home['body'], 'property="og:image" content="');
    check_contains($home['body'], 'og-cover.png');
    check_contains($home['body'], 'name="twitter:card" content="summary_large_image"');
});

test('登录等私有页面不被收录', function (): void {
    $login = http('GET', '/login');
    check_contains($login['body'], '<meta name="robots" content="noindex, nofollow">');
    check_not_contains($login['body'], 'rel="canonical"');
});

test('www 域名 301 跳转到主域名', function (): void {
    $base = rtrim((string) getenv('BASE_URL'), '/');
    $host = (string) parse_url($base, PHP_URL_HOST);
    $headers = (string) shell_exec(
        'curl -sS -o /dev/null -D - --max-time 10 -H ' . escapeshellarg('Host: www.' . $host)
        . ' ' . escapeshellarg($base . '/about') . ' 2>&1'
    );
    check(stripos($headers, ' 301 ') !== false, 'www 域名应返回 301，响应头：' . $headers);
    check(stripos($headers, 'Location: ' . $base . '/about') !== false, '跳转目标应为主域名');
});

test('首页与关于页说明身份核验', function (): void {
    $home = http('GET', '/');
    check_contains($home['body'], '公开之前，先核验身份');
    $about = http('GET', '/about');
    check_contains($about['body'], 'id="verification"');
    check_contains($about['body'], '死亡证明');
});

test('未登录访问管理页跳转登录', function (): void {
    reset_session();
    $response = http('GET', '/manage/memorials/1', null, [], false);
    check($response['status'] === 302, '应返回 302');
    check(str_contains($response['location'], '/login'), '应跳转登录页');
});

test('未授权用户无法访问后台', function (): void {
    login('demo@example.com', 'Demo@123456');
    $response = http('GET', '/admin', null, [], false);
    check($response['status'] === 403, '普通用户应收到 403');
});

echo "\n[账号与权限]\n";

test('管理员后台可访问', function (): void {
    login('admin@example.com', 'TestAdmin@123');
    $response = http('GET', '/admin');
    check($response['status'] === 200, '管理员后台不可访问');
});

test('协作者不能修改纪念馆设置与成员', function (): void {
    login('family@example.com', 'Demo@123456');
    $settings = http('GET', '/manage/memorials/1/settings', null, [], false);
    check($settings['status'] === 403, '协作者不应访问设置，实际 ' . $settings['status']);
    $members = http('GET', '/manage/memorials/1/members', null, [], false);
    check($members['status'] === 403, '协作者不应访问成员管理，实际 ' . $members['status']);
});

test('馆主可访问设置与成员管理', function (): void {
    login('demo@example.com', 'Demo@123456');
    foreach (['/manage/memorials/1', '/manage/memorials/1/profile', '/manage/memorials/1/settings', '/manage/memorials/1/members', '/manage/memorials/1/media', '/manage/memorials/1/moderation', '/manage/memorials/1/verification'] as $path) {
        $response = http('GET', $path);
        check($response['status'] === 200, "{$path} 返回 " . $response['status']);
    }
});

test('新馆强制私密，未核验不可公开展示', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/new');
    $slug = 'verify-' . bin2hex(random_bytes(4));
    $name = '核验测试' . bin2hex(random_bytes(2));
    $response = http('POST', '/manage/memorials', [
        '_token' => $token,
        'full_name' => $name,
        'gender' => 'male',
        'birth_date' => '1930-01-02',
        'death_date' => '2020-03-04',
        'birth_place' => '江苏苏州',
        'death_place' => '江苏苏州',
        'bio' => '用于验证新馆默认私密与身份材料核验流程。',
        'title' => '纪念' . $name,
        'summary' => '核验流程测试纪念馆。',
        'visibility' => 'public',
        'slug' => $slug,
        'declaration' => '1',
    ]);
    check($response['status'] === 200, '创建纪念馆失败，状态 ' . $response['status']);

    $memorial = Memorial::findBySlug($slug);
    check($memorial !== null, '纪念馆未创建');
    check($memorial['visibility'] === 'private', '新馆应强制为 private，实际 ' . $memorial['visibility']);
    check($memorial['verification_status'] === 'none', '新馆核验状态应为 none');

    // 未核验时直接 POST 公开设置应被拒绝，且状态不变。
    $token = csrf_token('/manage/memorials/' . $memorial['id'] . '/settings');
    $blocked = http('POST', '/manage/memorials/' . $memorial['id'] . '/settings', [
        '_token' => $token,
        'visibility' => 'public',
        'allow_search_index' => '1',
        'allow_guestbook' => '1',
        'moderate_messages' => '1',
    ], [], false);
    check($blocked['status'] === 422, '未核验设置公开应返回 422，实际 ' . $blocked['status']);
    check(Memorial::find((int) $memorial['id'])['visibility'] === 'private', '未核验时访问范围不应变化');

    // 直接改为 active+public，模拟绕过表单；访客仍应看不到。
    Db::update('memorials', ['status' => 'active', 'visibility' => 'public', 'allow_search_index' => 1], 'id = ?', [$memorial['id']]);
    reset_session();
    $guest = http('GET', '/m/' . $slug, null, [], false);
    check($guest['status'] === 404, '未核验公开馆对访客应 404，实际 ' . $guest['status']);
    $list = http('GET', '/memorials?q=' . rawurlencode($name));
    check_not_contains($list['body'], '/m/' . $slug, '未核验纪念馆不应出现在公开列表');
    $sitemap = http('GET', '/sitemap.xml');
    check_not_contains($sitemap['body'], $slug, '未核验纪念馆不应进入 sitemap');

    // 馆主提交核验材料
    login('demo@example.com', 'Demo@123456');
    $verifyPage = http('GET', '/manage/memorials/' . $memorial['id'] . '/verification');
    check($verifyPage['status'] === 200, '核验页不可访问');
    $token = csrf_token('/manage/memorials/' . $memorial['id'] . '/verification');

    $image = imagecreatetruecolor(80, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, 220, 210, 190));
    $document = sys_get_temp_dir() . '/jingsi-verification-' . getmypid() . '.jpg';
    imagejpeg($image, $document, 80);
    imagedestroy($image);

    $submit = http('POST', '/manage/memorials/' . $memorial['id'] . '/verification', [
        '_token' => $token,
        'method' => 'death_certificate',
        'statement' => '我是逝者的女儿，现提交死亡证明与亲属关系材料，用于平台人工核验。',
    ], ['document' => $document]);
    @unlink($document);
    check($submit['status'] === 200, '核验材料提交失败，状态 ' . $submit['status']);

    $submission = Db::fetch('SELECT * FROM memorial_verifications WHERE memorial_id = ? ORDER BY id DESC LIMIT 1', [$memorial['id']]);
    check($submission !== null, '核验记录未写入');
    check($submission['status'] === 'pending', '核验记录应为 pending');
    check((bool) Storage::verificationAbsolute($submission['document_path']), '核验文件未落入私有目录');
    $memorial = Memorial::find((int) $memorial['id']);
    check($memorial['verification_status'] === 'pending', '纪念馆应进入 pending');
    check($memorial['visibility'] === 'private', '提交核验后应立即回退私密');
    check((int) $memorial['allow_search_index'] === 0, '提交核验后应关闭搜索收录');

    // 材料仅管理员可下载
    $submissionId = (int) $submission['id'];
    reset_session();
    $guestDoc = http('GET', '/admin/verifications/' . $submissionId . '/document', null, [], false);
    check($guestDoc['status'] === 302, '访客下载核验材料应跳登录');
    login('demo@example.com', 'Demo@123456');
    $ownerDoc = http('GET', '/admin/verifications/' . $submissionId . '/document', null, [], false);
    check($ownerDoc['status'] === 403, '馆主不应能访问后台核验材料，实际 ' . $ownerDoc['status']);

    login('admin@example.com', 'TestAdmin@123');
    $adminDoc = http('GET', '/admin/verifications/' . $submissionId . '/document');
    check($adminDoc['status'] === 200, '管理员应能下载核验材料');
    check_contains($adminDoc['headers'], 'image/jpeg', '核验材料内容类型错误');

    $token = csrf_token('/admin/verifications/' . $submissionId);
    $review = http('POST', '/admin/verifications/' . $submissionId . '/review', [
        '_token' => $token,
        'action' => 'approve',
        'review_note' => '材料清晰，与声明一致，予以通过。',
    ]);
    check($review['status'] === 200, '审核通过失败');
    $memorial = Memorial::find((int) $memorial['id']);
    check($memorial['verification_status'] === 'verified', '审核后应为 verified');
    $submission = Db::fetch('SELECT * FROM memorial_verifications WHERE id = ?', [$submissionId]);
    check($submission['status'] === 'approved', '核验记录应 approved');

    // 核验通过后馆主才可公开
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/' . $memorial['id'] . '/settings');
    $settings = http('POST', '/manage/memorials/' . $memorial['id'] . '/settings', [
        '_token' => $token,
        'visibility' => 'public',
        'allow_search_index' => '1',
        'allow_guestbook' => '1',
        'moderate_messages' => '1',
    ]);
    check($settings['status'] === 200, '核验后设置公开失败');
    $memorial = Memorial::find((int) $memorial['id']);
    check($memorial['visibility'] === 'public', '核验后应可设为公开');
});

test('驳回核验后强制回退私密', function (): void {
    $ownerId = (int) Db::value('SELECT id FROM users WHERE email = ?', ['demo@example.com']);
    $slug = 'reject-' . bin2hex(random_bytes(4));
    $memorialId = Memorial::create(
        ['slug' => $slug, 'title' => '核验驳回测试', 'summary' => '测试驳回后的访问范围。', 'visibility' => 'public'],
        [
            'full_name' => '驳回测试' . bin2hex(random_bytes(2)),
            'gender' => 'female',
            'birth_date' => '1935-01-01',
            'death_date' => '2021-01-01',
            'birth_place' => '江苏',
            'death_place' => '江苏',
            'bio' => '用于测试核验驳回后的访问范围回退。',
        ],
        $ownerId
    );
    Memorial::update($memorialId, ['status' => 'active', 'visibility' => 'public', 'allow_search_index' => 1]);
    $verificationId = Verification::create([
        'memorial_id' => $memorialId,
        'submitted_by' => $ownerId,
        'method' => 'death_certificate',
        'statement' => '测试材料，用于验证驳回后的安全回退。',
    ]);

    login('admin@example.com', 'TestAdmin@123');
    $token = csrf_token('/admin/verifications/' . $verificationId);
    $response = http('POST', '/admin/verifications/' . $verificationId . '/review', [
        '_token' => $token,
        'action' => 'reject',
        'review_note' => '材料无法辨认，请补充清晰文件后重新提交。',
    ]);
    check($response['status'] === 200, '驳回操作失败');

    $memorial = Memorial::find($memorialId);
    check($memorial['verification_status'] === 'rejected', '驳回后应为 rejected');
    check($memorial['visibility'] === 'private', '驳回后必须回退 private');
    check((int) $memorial['allow_search_index'] === 0, '驳回后必须关闭搜索收录');
});

test('AI 工作室仅馆主可用', function (): void {
    login('family@example.com', 'Demo@123456');
    $editor = http('GET', '/manage/memorials/1/ai', null, [], false);
    check($editor['status'] === 403, '协作者不应使用 AI 工作室，实际 ' . $editor['status']);

    login('demo@example.com', 'Demo@123456');
    $owner = http('GET', '/manage/memorials/1/ai');
    check($owner['status'] === 200, '馆主 AI 工作室不可访问');
    check_contains($owner['body'], '老照片修复');
    check_contains($owner['body'], '家族数字档案');
});

test('AI 照片修复生成新照片副本', function (): void {
    login('demo@example.com', 'Demo@123456');
    $photoId = (int) Db::value("SELECT id FROM media WHERE memorial_id = 1 AND kind = 'photo' ORDER BY id ASC LIMIT 1");
    check($photoId > 0, '缺少演示照片');
    $token = csrf_token('/manage/memorials/1/ai');
    $response = http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $token,
        'service' => 'photo_restore',
        'media_id' => (string) $photoId,
        'caption' => '集成测试修复照片',
        'consent' => '1',
    ]);
    check($response['status'] === 200, '照片修复任务提交失败');
    $job = Db::fetch("SELECT * FROM ai_jobs WHERE service_slug = 'photo_restore' ORDER BY id DESC LIMIT 1");
    check($job !== null && $job['status'] === 'completed', '照片修复任务未完成');
    $media = Media::find((int) $job['media_id']);
    check($media !== null && $media['kind'] === 'photo', '未生成照片媒体');
    check($media['visibility'] === 'members', 'AI 输出应默认仅成员可见');
    check((bool) Storage::absolute((string) $media['disk_path']), 'AI 照片文件不存在');
});

test('AI 生平文章以草稿保存', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/1/ai');
    $response = http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $token,
        'service' => 'life_article',
        'title' => '集成测试生平草稿',
        'consent' => '1',
    ]);
    check($response['status'] === 200, '生平任务提交失败');
    $job = Db::fetch("SELECT * FROM ai_jobs WHERE service_slug = 'life_article' ORDER BY id DESC LIMIT 1");
    check($job !== null && $job['status'] === 'completed', '生平任务未完成');
    $article = \App\Models\Article::find((int) $job['article_id']);
    check($article !== null, '未生成文章');
    check($article['status'] === 'draft', 'AI 文章必须以草稿保存');
    check(mb_strlen((string) $article['content']) > 50, '文章内容过短');
});

test('AI 家族档案生成可下载 HTML', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/1/ai');
    $response = http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $token,
        'service' => 'family_archive',
        'consent' => '1',
    ]);
    check($response['status'] === 200, '家族档案任务提交失败');
    $job = Db::fetch("SELECT * FROM ai_jobs WHERE service_slug = 'family_archive' ORDER BY id DESC LIMIT 1");
    check($job !== null && $job['status'] === 'completed', '家族档案任务未完成');
    $media = Media::find((int) $job['media_id']);
    check($media !== null && str_starts_with((string) $media['mime'], 'text/html'), '档案 MIME 应为 HTML');
    $path = Storage::absolute((string) $media['disk_path']);
    check($path !== null && filesize($path) > 1000, '档案文件为空');
    check_contains((string) file_get_contents($path), '家族数字档案');
});

test('AI 纪念短片生成 MP4', function (): void {
    login('demo@example.com', 'Demo@123456');
    $ids = Db::fetchAll("SELECT id FROM media WHERE memorial_id = 1 AND kind = 'photo' ORDER BY id ASC LIMIT 2");
    check(count($ids) === 2, '演示照片不足');
    $token = csrf_token('/manage/memorials/1/ai');
    $response = http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $token,
        'service' => 'memorial_video',
        'media_ids' => [(string) $ids[0]['id'], (string) $ids[1]['id']],
        'consent' => '1',
    ]);
    check($response['status'] === 200, '短片任务提交失败');
    $job = Db::fetch("SELECT * FROM ai_jobs WHERE service_slug = 'memorial_video' ORDER BY id DESC LIMIT 1");
    check($job !== null && $job['status'] === 'completed', '短片任务未完成：' . ($job['error_message'] ?? ''));
    $media = Media::find((int) $job['media_id']);
    check($media !== null && $media['kind'] === 'video' && $media['mime'] === 'video/mp4', '未生成 MP4');
    $path = Storage::absolute((string) $media['disk_path']);
    check($path !== null && filesize($path) > 1024, '短片文件为空');
});

test('运营可编辑 AI 占位价格并同步展示', function (): void {
    login('admin@example.com', 'TestAdmin@123');
    $service = Db::fetch("SELECT * FROM ai_services WHERE slug = 'photo_restore' LIMIT 1");
    check($service !== null, '缺少 AI 服务配置');
    $token = csrf_token('/admin/ai-services');
    $response = http('POST', '/admin/ai-services/' . (int) $service['id'], [
        '_token' => $token,
        'price_yuan' => '123.45',
        'price_note' => '测试占位价',
        'enabled' => '1',
    ]);
    check($response['status'] === 200, '价格更新失败');
    check((int) Db::value("SELECT price_cents FROM ai_services WHERE slug = 'photo_restore'") === 12345, '价格未保存');
    $public = http('GET', '/services');
    check_contains($public['body'], '¥123.45', '服务页未展示更新后的价格');
});

test('服务页明码标价且不卖虚拟祭品', function (): void {
    $response = http('GET', '/services');
    check($response['status'] === 200, '服务页不可访问');
    check_contains($response['body'], '80–300 元/张', '缺少照片修复参考价');
    check_contains($response['body'], '不卖虚拟祭品', '缺少不卖虚拟祭品说明');
    check_contains($response['body'], '不自动续费', '缺少续费说明');
});

test('后台 AI 大模型设置读写与校验', function (): void {
    login('admin@example.com', 'TestAdmin@123');
    $page = http('GET', '/admin/ai-settings');
    check($page['status'] === 200, '后台 AI 设置页不可访问');
    check_contains($page['body'], 'AI 大模型设置', '页面缺少标题');
    check_contains($page['body'], 'API Key', '页面缺少密钥字段');

    // 非法 provider 与超范围数值 → 422
    $token = csrf_token('/admin/ai-settings');
    $bad = http('POST', '/admin/ai-settings', [
        '_token' => $token,
        'provider' => 'midjourney',
        'base_url' => 'https://api.openai.com/v1',
        'text_model' => '',
        'image_model' => '',
        'timeout' => '1',
        'daily_limit' => '0',
    ]);
    check($bad['status'] === 422, '非法 provider/数值应 422');
    check_contains($bad['body'], '运行模式只能是', '错误信息缺失');

    // openai 模式缺 Key → 422
    $bad = http('POST', '/admin/ai-settings', [
        '_token' => $token,
        'provider' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'text_model' => 'gpt-4.1-mini',
        'image_model' => 'gpt-image-1',
        'timeout' => '120',
        'daily_limit' => '10',
    ]);
    check($bad['status'] === 422, 'openai 缺 Key 应 422');

    // 正确保存 → 302 回设置页，DB 落库，内存 config 生效
    $ok = http('POST', '/admin/ai-settings', [
        '_token' => $token,
        'provider' => 'openai',
        'base_url' => 'https://api.example-proxy.com/v1/',
        'api_key' => 'sk-test-abcdef123456',
        'text_model' => 'gpt-4.1-mini',
        'image_model' => 'gpt-image-1',
        'timeout' => '90',
        'daily_limit' => '7',
        'sync' => '0',
        'enabled' => '1',
    ], [], false);
    check($ok['status'] === 302, '保存应成功跳转，实际 ' . $ok['status']);
    // CSRF token 是按页面路径缓存的：保存后重开设置页取新 token
    $again = http('GET', '/admin/ai-settings');
    check_contains($again['body'], '已配置，来自设置', '保存后未显示已配置状态');
    check_contains($again['body'], 'api.example-proxy.com', '生效值未更新');
    $row = Db::fetch("SELECT * FROM platform_settings WHERE name = 'ai.provider' LIMIT 1");
    check($row !== null && $row['value'] === 'openai', 'platform_settings 未落库（provider）');
    $sync = Db::fetch("SELECT * FROM platform_settings WHERE name = 'ai.sync' LIMIT 1");
    check($sync !== null && $sync['value'] === '0', '执行方式未落库');

    // demo 模式 + 清空 Key 一并保存，回退验证
    $token2 = csrf_token('/admin/ai-settings');
    $back = http('POST', '/admin/ai-settings', [
        '_token' => $token2,
        'provider' => 'demo',
        'enabled' => '1',
        'sync' => '1',
        'clear_key' => '1',
        'timeout' => '120',
        'daily_limit' => '10',
    ], [], false);
    check($back['status'] === 302, '回退保存失败');
    check(Db::fetch("SELECT * FROM platform_settings WHERE name = 'ai.api_key' LIMIT 1") === null, '清空 Key 后不应残留');
    $page2 = http('GET', '/admin/ai-settings');
    check(!str_contains((string) $page2['body'], '已配置，来自设置'), '清空后不应再显示设置源密钥');

    // 模型列表探测：不可达地址 → 502 JSON，不回传 Key
    $probe = http('POST', '/admin/ai-settings/models', [
        '_token' => $token2,
        'base_url' => 'https://127.0.0.1:9/v1',
        'api_key' => 'sk-model-list-probe',
    ]);
    check(in_array($probe['status'], [502, 422], true), '不可达地址应 502/422，实际 ' . $probe['status']);
    check(!str_contains((string) $probe['body'], 'sk-model-list-probe'), '响应不得回显 Key');

    // 视频组：非法独立 provider → 422
    $token3 = csrf_token('/admin/ai-settings');
    $badVideo = http('POST', '/admin/ai-settings', [
        '_token' => $token3,
        'provider' => 'demo',
        'enabled' => '1',
        'sync' => '1',
        'video_provider' => 'sora',
        'timeout' => '120',
        'daily_limit' => '10',
    ], [], false);
    check($badVideo['status'] === 422, '非法视频独立 provider 应 422，实际 ' . $badVideo['status']);

    // 视频组合法保存：四键独立落库
    $okVideo = http('POST', '/admin/ai-settings', [
        '_token' => $token3,
        'provider' => 'demo',
        'enabled' => '1',
        'sync' => '1',
        'video_provider' => 'openai',
        'video_base_url' => 'https://video.example-proxy.com/v1',
        'video_api_key' => 'sk-test-video-key',
        'video_model' => 'sora-2',
        'timeout' => '120',
        'daily_limit' => '10',
    ], [], false);
    check($okVideo['status'] === 302, '视频组保存应 302，实际 ' . $okVideo['status']);
    foreach ([
        'ai.video.provider' => 'openai',
        'ai.video.base_url' => 'https://video.example-proxy.com/v1',
        'ai.video.api_key' => 'sk-test-video-key',
        'ai.video.model' => 'sora-2',
    ] as $name => $expect) {
        $row = Db::fetch('SELECT value FROM platform_settings WHERE name = ?', [$name]);
        check($row !== null && $row['value'] === $expect, "{$name} 未按预期落库");
    }
    $page3 = http('GET', '/admin/ai-settings');
    check_contains($page3['body'], '已配置，来自设置·视频', '视频组掩码提示缺失');
    check_contains($page3['body'], 'video.example-proxy.com', '视频组生效地址未回显');
    check_contains($page3['body'], 'sora-2', '视频组生效模型未回显');
    check_contains($page3['body'], 'ai-fetch-video-models', '缺少视频域获取模型按钮');
    check_contains($page3['body'], 'clear_video', '缺少视频组清空复选');

    // 视频组清空：四键全部回退主组
    $token4 = csrf_token('/admin/ai-settings');
    $clearVideo = http('POST', '/admin/ai-settings', [
        '_token' => $token4,
        'provider' => 'demo',
        'enabled' => '1',
        'sync' => '1',
        'clear_video' => '1',
        'timeout' => '120',
        'daily_limit' => '10',
    ], [], false);
    check($clearVideo['status'] === 302, 'clear_video 保存应 302，实际 ' . $clearVideo['status']);
    $left = Db::fetchAll("SELECT name FROM platform_settings WHERE name LIKE 'ai.video.%'");
    check(count($left) === 0, 'clear_video 后视频组键未清空：' . json_encode($left));
    $page4 = http('GET', '/admin/ai-settings');
    check(!str_contains((string) $page4['body'], '已配置，来自设置·视频'), '清空后视频组不应再显示独立密钥提示');

    try {
        // 页面状态断言结束：清理设置回 env 默认，供后续用例使用 demo 默认
    } finally {
        Db::query("DELETE FROM platform_settings WHERE name LIKE 'ai.%'");
        \App\Core\Settings::reset();
    }
});

test('注册流程（含算术验证码）', function (): void {
    reset_session();
    $response = http('GET', '/register');
    check($response['status'] === 200, '注册页不可访问');
    if (!preg_match('/(\d+)\s*\+\s*(\d+)\s*=/u', $response['body'], $matches)) {
        throw new RuntimeException('未找到验证码题目');
    }
    $token = '';
    if (preg_match('/name="_token"\s+value="([a-f0-9]{32,})"/', $response['body'], $tokenMatch)) {
        $token = $tokenMatch[1];
    }
    $email = 'test' . time() . '@example.com';
    $result = http('POST', '/register', [
        '_token' => $token,
        'display_name' => '测试用户',
        'email' => $email,
        'password' => 'TestPass12345',
        'password_confirmation' => 'TestPass12345',
        'captcha' => (string) ((int) $matches[1] + (int) $matches[2]),
        'terms' => '1',
    ]);
    check($result['status'] === 200, '注册失败，状态 ' . $result['status']);
    check(effective_path($result['url']) === '/dashboard', '注册后未登录，停留在 ' . effective_path($result['url']));
    check(db_count('users', 'email = ?', [$email]) === 1, '用户未写入');
    logout();
});

echo "\n[互动与审核]\n";

test('祭奠写入与频率限制字段', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/m/zhang-mingyuan');
    $before = db_count('tributes');
    $response = http('POST', '/m/zhang-mingyuan/tributes', [
        '_token' => $token,
        'type' => 'flower',
        'note' => '测试献花',
        'anonymous' => '1',
    ]);
    check($response['status'] === 200, '祭奠提交失败');
    check(db_count('tributes') === $before + 1, '祭奠记录未写入');
    $row = Db::fetch('SELECT * FROM tributes ORDER BY id DESC LIMIT 1');
    check($row['status'] === 'pending', '带附言且开启审核时应为 pending');
    check(!empty($row['ip_hash']), '应记录 IP hash');
});

test('留言进入待审核并可由馆主通过', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/m/zhang-mingyuan');
    $content = '集成测试留言 ' . bin2hex(random_bytes(4));
    $response = http('POST', '/m/zhang-mingyuan/messages', [
        '_token' => $token,
        'content' => $content,
    ]);
    check($response['status'] === 200, '留言提交失败');
    $row = Db::fetch('SELECT * FROM messages WHERE content = ? LIMIT 1', [$content]);
    check($row !== null, '留言未写入');
    check($row['status'] === 'pending', '留言应为待审核');

    $manageToken = csrf_token('/manage/memorials/1/moderation');
    $moderate = http('POST', '/manage/memorials/1/moderation/messages/' . $row['id'], [
        '_token' => $manageToken,
        'status' => 'visible',
    ]);
    check($moderate['status'] === 200, '审核操作失败');
    $updated = Message::find((int) $row['id']);
    check($updated['status'] === 'visible', '留言状态未更新');
});

test('留言转义，防止存储型 XSS', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');
    $payload = '<script>alert(1)</script>';
    $id = Message::create((int) $memorial['id'], null, '测试', $payload, null, 'visible');
    $response = http('GET', '/m/zhang-mingyuan/messages');
    check_not_contains($response['body'], $payload, '未转义的脚本被输出');
    check_contains($response['body'], '&lt;script&gt;alert(1)&lt;/script&gt;', '转义输出缺失');
    Db::query('DELETE FROM messages WHERE id = ?', [$id]);
});

test('管理员批量审核留言、文章与媒体', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');

    // 三条待审留言：两条批量通过，一条单条路径已在前面用例覆盖
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = Message::create((int) $memorial['id'], null, '批量测试', '批量审核留言 ' . bin2hex(random_bytes(4)), null, 'pending');
    }

    logout();
    login('admin@example.com', 'TestAdmin@123');

    // 无勾选：422 并提示
    $empty = http('POST', '/admin/moderation/messages/batch', ['_token' => csrf_token('/admin/moderation')]);
    check($empty['status'] === 422, '未勾选应 422，实际 ' . $empty['status']);

    // 勾选两条 + 一个不存在的 id：仅两条被通过
    $batch = http('POST', '/admin/moderation/messages/batch', [
        '_token' => csrf_token('/admin/moderation'),
        'status' => 'visible',
        'ids' => [$ids[0], $ids[1], '99999999'],
    ]);
    check($batch['status'] === 200, '批量留言审核失败，状态 ' . $batch['status']);
    check(Message::find((int) $ids[0])['status'] === 'visible', '留言 1 应被批量通过');
    check(Message::find((int) $ids[1])['status'] === 'visible', '留言 2 应被批量通过');
    check(Message::find((int) $ids[2])['status'] === 'pending', '留言 3 不应受影响');

    // 已通过展示的留言 1 再次进入批量：pnen 仅处理 pending，应跳过保持 visible
    $reapply = http('POST', '/admin/moderation/messages/batch', [
        '_token' => csrf_token('/admin/moderation'),
        'status' => 'hidden',
        'ids' => [$ids[0]],
    ]);
    check($reapply['status'] === 200, '批量提交失败');
    check(Message::find((int) $ids[0])['status'] === 'visible', '非 pending 留言应被跳过；不应被批量隐藏');

    // 文章：两篇 pending，一篇批量驳回（带说明），一篇批量通过
    $authorId = (int) Db::value("SELECT id FROM users WHERE email = 'demo@example.com' LIMIT 1");
    $articleSeed = static fn(string $title): array => [
        'title' => $title,
        'content' => '批量审核文章内容，长度需满足校验要求，因此这里写足够长的正文以确保测试稳定通过。',
        'author_name' => '批量测试',
        'status' => 'pending',
    ];
    $a1 = Article::create((int) $memorial['id'], $articleSeed('批量文章 A ' . bin2hex(random_bytes(3))), $authorId);
    $a2 = Article::create((int) $memorial['id'], $articleSeed('批量文章 B ' . bin2hex(random_bytes(3))), $authorId);
    $reject = http('POST', '/admin/moderation/articles/batch', [
        '_token' => csrf_token('/admin/moderation'),
        'action' => 'reject',
        'review_note' => '批量驳回说明',
        'ids' => [$a1],
    ]);
    check($reject['status'] === 200, '批量驳回文章失败，状态 ' . $reject['status']);
    $rowA = Article::find((int) $a1);
    check($rowA['status'] === 'rejected', '文章 A 应被驳回');
    check($rowA['review_note'] === '批量驳回说明', '驳回说明应落库');
    $approve = http('POST', '/admin/moderation/articles/batch', [
        '_token' => csrf_token('/admin/moderation'),
        'action' => 'approve',
        'ids' => [$a2],
    ]);
    check($approve['status'] === 200, '批量通过文章失败');
    check(Article::find((int) $a2)['status'] === 'published', '文章 B 应发布');

    logout();

    foreach ($ids as $id) {
        Db::query('DELETE FROM messages WHERE id = ?', [$id]);
    }
    Db::query('DELETE FROM articles WHERE id IN (?, ?)', [$a1, $a2]);
});

echo "\n[隐私与媒体鉴权]\n";

test('私密纪念馆访客不可见、成员可见', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');
    Db::update('memorials', ['visibility' => 'private'], 'id = ?', [$memorial['id']]);
    try {
        reset_session();
        $guest = http('GET', '/m/zhang-mingyuan', null, [], false);
        check($guest['status'] === 404, '访客应收到 404');
        login('demo@example.com', 'Demo@123456');
        $member = http('GET', '/m/zhang-mingyuan');
        check($member['status'] === 200, '成员应可访问');
        check(
            str_contains($member['body'], '私密') || str_contains($member['body'], '仅成员'),
            '应展示私密/仅成员提示'
        );
    } finally {
        Db::update('memorials', ['visibility' => 'public'], 'id = ?', [$memorial['id']]);
    }
});

test('成员可见媒体经过服务端鉴权', function (): void {
    $media = Db::fetch('SELECT * FROM media ORDER BY id ASC LIMIT 1');
    check($media !== null, '缺少媒体');
    Db::update('media', ['visibility' => 'members'], 'id = ?', [$media['id']]);
    try {
        reset_session();
        $guest = http('GET', '/media/' . $media['id'], null, [], false);
        check($guest['status'] === 404, '访客应收到 404');
        login('demo@example.com', 'Demo@123456');
        $member = http('GET', '/media/' . $media['id']);
        check($member['status'] === 200, '成员应可读取');
        check_contains($member['headers'], 'image/jpeg', '内容类型错误');
    } finally {
        Db::update('media', ['visibility' => 'visitors'], 'id = ?', [$media['id']]);
    }
});

test('馆主可切换媒体可见范围并立即生效', function (): void {
    $media = Db::fetch("SELECT * FROM media WHERE memorial_id = 1 AND kind = 'photo' AND status = 'active' ORDER BY id ASC LIMIT 1");
    check($media !== null, '缺少可测试照片');

    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/1/media');
    $response = http('POST', '/manage/memorials/1/media/' . $media['id'] . '/visibility', [
        '_token' => $token,
        'visibility' => 'members',
    ]);
    check($response['status'] === 200, '切换为仅成员失败');
    check(Media::find((int) $media['id'])['visibility'] === 'members', '数据库未更新为 members');

    reset_session();
    $guest = http('GET', '/media/' . $media['id'], null, [], false);
    check($guest['status'] === 404, '仅成员照片对访客应 404');

    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/1/media');
    http('POST', '/manage/memorials/1/media/' . $media['id'] . '/visibility', [
        '_token' => $token,
        'visibility' => 'visitors',
    ]);
    check(Media::find((int) $media['id'])['visibility'] === 'visitors', '未能恢复访客可见');
});

test('成员可见照片不会作为公开封面输出', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');
    $media = Db::fetch("SELECT * FROM media WHERE memorial_id = ? AND kind = 'photo' ORDER BY id ASC LIMIT 1", [$memorial['id']]);
    check($media !== null, '缺少照片');
    $originalCover = $memorial['cover_media_id'];
    Db::update('media', ['visibility' => 'members'], 'id = ?', [$media['id']]);
    Db::update('deceased', ['cover_media_id' => $media['id']], 'id = ?', [$memorial['deceased_id']]);
    try {
        reset_session();
        $guest = http('GET', '/m/zhang-mingyuan');
        check($guest['status'] === 200, '公开页不可访问');
        check_not_contains($guest['body'], '/media/' . $media['id'], '成员可见照片被作为封面公开输出');
        login('demo@example.com', 'Demo@123456');
        $member = http('GET', '/m/zhang-mingyuan');
        check_contains($member['body'], '/media/' . $media['id'], '成员应能看到封面');
    } finally {
        Db::update('media', ['visibility' => 'visitors'], 'id = ?', [$media['id']]);
        Db::update('deceased', ['cover_media_id' => $originalCover], 'id = ?', [$memorial['deceased_id']]);
    }
});

test('草稿纪念馆不对外展示', function (): void {
    $memorial = Memorial::findBySlug('zhang-mingyuan');
    Db::update('memorials', ['status' => 'draft'], 'id = ?', [$memorial['id']]);
    try {
        reset_session();
        $guest = http('GET', '/m/zhang-mingyuan', null, [], false);
        check($guest['status'] === 404, '草稿应对外 404');
    } finally {
        Db::update('memorials', ['status' => 'active'], 'id = ?', [$memorial['id']]);
    }
});

echo "\n[举报与服务]\n";

test('匿名举报提交并入库', function (): void {
    reset_session();
    $response = http('GET', '/report?type=memorial&id=1');
    check($response['status'] === 200, '举报页不可访问');
    if (!preg_match('/name="_token"\s+value="([a-f0-9]{32,})"/', $response['body'], $tokenMatch)) {
        throw new RuntimeException('缺少 CSRF token');
    }
    if (!preg_match('/(\d+)\s*\+\s*(\d+)\s*=?/u', $response['body'], $captcha)) {
        throw new RuntimeException('缺少验证码');
    }
    $before = db_count('reports');
    $result = http('POST', '/report', [
        '_token' => $tokenMatch[1],
        'target_type' => 'memorial',
        'target_id' => '1',
        'reason' => 'privacy',
        'detail' => '集成测试举报，涉及隐私信息，请核查。',
        'reporter_name' => '测试访客',
        'contact' => 'test@example.com',
        'captcha' => (string) ((int) $captcha[1] + (int) $captcha[2]),
    ]);
    check($result['status'] === 200, '举报提交失败');
    check_contains($result['body'], '已提交', '缺少成功提示');
    check(db_count('reports') === $before + 1, '举报未入库');
    check(Db::value('SELECT priority FROM reports ORDER BY id DESC LIMIT 1') === 'high', '隐私举报应为 high');
});

test('活人被祭奠举报标记为 urgent', function (): void {
    check(Report::priorityForReason('living_person') === 'urgent', 'living_person 应为 urgent');
    check(Report::priorityForReason('fake_person') === 'urgent', 'fake_person 应为 urgent');
    check(Report::priorityForReason('insult') === 'high', 'insult 应为 high');

    $id = Report::create([
        'target_type' => 'memorial',
        'target_id' => 1,
        'memorial_id' => 1,
        'reporter_name' => '测试访客',
        'reason' => 'living_person',
        'detail' => '该纪念馆所祭奠的人仍在世，请立即核实并先行暂停。',
    ]);
    check(Db::value('SELECT priority FROM reports WHERE id = ?', [$id]) === 'urgent', '紧急举报未标记 urgent');
    Db::query('DELETE FROM reports WHERE id = ?', [$id]);
});

test('举报入口不泄露私密/待审内容', function (): void {
    $pendingMessage = Db::fetch("SELECT * FROM messages WHERE status = 'pending' LIMIT 1");
    check($pendingMessage !== null, '缺少待审留言');
    reset_session();
    $guest = http('GET', '/report?type=message&id=' . $pendingMessage['id'], null, [], false);
    check($guest['status'] === 404, '访客不应通过举报表单看到待审留言');

    $media = Db::fetch("SELECT * FROM media WHERE kind = 'photo' ORDER BY id ASC LIMIT 1");
    check($media !== null, '缺少照片');
    Db::update('media', ['visibility' => 'members'], 'id = ?', [$media['id']]);
    try {
        $guestMedia = http('GET', '/report?type=media&id=' . $media['id'], null, [], false);
        check($guestMedia['status'] === 404, '访客不应通过举报表单看到成员媒体');
        login('demo@example.com', 'Demo@123456');
        $memberMedia = http('GET', '/report?type=media&id=' . $media['id']);
        check($memberMedia['status'] === 200, '成员应可举报可见媒体');
    } finally {
        Db::update('media', ['visibility' => 'visitors'], 'id = ?', [$media['id']]);
    }
});

echo "\n[上传安全]\n";

test('非法文件类型被拒绝', function (): void {
    login('demo@example.com', 'Demo@123456');
    $token = csrf_token('/manage/memorials/1/media');
    $badFile = sys_get_temp_dir() . '/jingsi-bad-' . getmypid() . '.php';
    file_put_contents($badFile, '<?php echo "pwned";');
    $before = db_count('media');
    $response = http('POST', '/manage/memorials/1/media', [
        '_token' => $token,
        'kind' => 'photo',
        'visibility' => 'visitors',
        'caption' => 'bad',
    ], ['file' => $badFile]);
    @unlink($badFile);
    check($response['status'] === 200, '请求应返回页面');
    check(db_count('media') === $before, '非法文件不应入库');
});

echo "\n[小程序 API]\n";

test('API 登录与 401 错误包络', function (): void {
    $bad = api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'wrong-pass']);
    check($bad['status'] === 401, '错误凭证应返回 401');
    check(($bad['data']['error']['code'] ?? '') === 'unauthorized', '错误包络 code 应为 unauthorized');

    $guest = api('GET', '/api/v1/me');
    check($guest['status'] === 401, '未携带 token 访问 /me 应 401');
    check(($guest['data']['error']['code'] ?? '') === 'unauthorized', '未鉴权错误码应为 unauthorized');

    $ok = api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']);
    check($ok['status'] === 200, '正确凭证应返回 200');
    $GLOBALS['apiToken'] = api_token_of($ok);
    check($GLOBALS['apiToken'] !== '', '应签发非空 token');
    check(api_token_of($ok) === api_token_of($ok), 'token 应可提取');

    $me = api('GET', '/api/v1/me', null, $GLOBALS['apiToken']);
    check($me['status'] === 200, 'token 应可访问 /me');
    check(str_ends_with((string) ($me['data']['data']['user']['email'] ?? ''), '@example.com'), '应返回（打码后的）用户邮箱');
    $roles = array_column($me['data']['data']['memberships'] ?? [], 'role', 'slug');
    check(($roles['zhang-mingyuan'] ?? '') === 'owner', '应含馆主角色成员身份');
});

test('API 微信登录链路与未配置回退', function (): void {
    // 已配置凭据 + 假 code：应进入真实交换层并以交换错误（而非“未配置”）拒绝。
    $fake = api('POST', '/api/v1/auth/wechat', ['code' => 'FAKE_CODE_TESTSUITE']);
    check($fake['status'] !== 503 || ($fake['data']['error']['code'] ?? '') !== 'wechat_not_configured', '凭据已配置时不应报 wechat_not_configured');
    $code = $fake['data']['error']['code'] ?? '';
    $offline = $code === 'service_unavailable'; // 环境断网：stub 允许，但不得声称“未配置”
    check(
        $offline || $code === 'wechat_exchange_failed' || $code === 'rate_limited' || $fake['status'] === 501 || $fake['status'] === 429,
        '假 code 应被微信交换层拒绝（wechat_exchange_failed/429，断网环境允许 service_unavailable），而非未配置'
    );

    // 缺 code：参数校验 422。
    $empty = api('POST', '/api/v1/auth/wechat', []);
    check($empty['status'] === 422, '缺少 code 应 422');
    check(($empty['data']['error']['code'] ?? '') === 'validation_failed', '缺少 code 错误码应为 validation_failed');
});

test('API 列表与公开详情', function (): void {
    $list = api('GET', '/api/v1/memorials');
    check($list['status'] === 200, '公开列表应 200');
    $slugs = array_column($list['data']['data']['items'] ?? [], 'slug');
    check(in_array('zhang-mingyuan', $slugs, true), '列表应含公开可收录馆');
    check(!in_array('memory-11bbb2ba', $slugs, true), '列表不应含私密馆');

    $detail = api('GET', '/api/v1/memorials/zhang-mingyuan');
    check($detail['status'] === 200, '公开馆详情应 200');
    check(isset($detail['data']['data']['profile']['name']) || isset($detail['data']['data']['summary']), '详情应含档案字段');
});

test('私密馆对无权限请求 404', function (): void {
    $guest = api('GET', '/api/v1/memorials/memory-11bbb2ba');
    check($guest['status'] === 404, '访客访问私密馆应 404');
    check(($guest['data']['error']['code'] ?? '') === 'not_found', '私密馆缺失应与其他 404 一致');
});

test('祭奠写入与留言待审', function (): void {
    $token = $GLOBALS['apiToken'] ?? '';
    if ($token === '') {
        check(false, '前置：缺少可用 API token');
        return;
    }
    $flower = api('POST', '/api/v1/memorials/zhang-mingyuan/tribute', ['type' => 'flower', 'appendix' => 'API 自检', 'anonymous' => true], $token);
    check($flower['status'] === 200 && isset($flower['data']['data']['id']), '献花应成功');

    $bad = api('POST', '/api/v1/memorials/zhang-mingyuan/tribute', ['type' => 'rocket'], $token);
    check($bad['status'] === 422, '非法祭奠类型应 422');

    $pending = api('POST', '/api/v1/memorials/zhang-mingyuan/message', ['body' => 'API 测试留言，请转公开后删除'], $token);
    check(in_array($pending['status'], [200, 202], true), '留言应入库');
    $GLOBALS['apiPendingMessage'] = $pending['data']['data']['message_id'] ?? null;

    $guest = api('GET', '/api/v1/memorials/zhang-mingyuan/messages');
    check($guest['status'] === 200, '留言簿应 200');
    $bodies = json_encode($guest['data']['data']['items'] ?? [], JSON_UNESCAPED_UNICODE);
    check(strpos((string) $bodies, 'API 测试留言') === false, '未登录留言簿不应含待审留言');
});

test('签名媒体：有效签名 200，篡改 404', function (): void {
    $detail = api('GET', '/api/v1/memorials/zhang-mingyuan');
    $media = $detail['data']['data']['media'] ?? [];
    check($media !== [], '公开馆详情应含媒体');
    $url = $media[0]['media_url'] ?? '';
    check($url !== '' && str_contains($url, 'sig='), '媒体应返回签名 URL');

    $valid = api('GET', $url);
    check($valid['status'] === 200, '有效签名媒体应 200');

    $tampered = api('GET', $url . 'x');
    check($tampered['status'] === 404, '篡改签名应 404');

    $stale = api('GET', preg_replace('/exp=\d+/', 'exp=1000000000', $url));
    check($stale['status'] === 404, '过期签名应 404');
});

test('claim 提交与越权拒绝', function (): void {
    // 演示数据为 claimant 预置了一条待处理申领：先临时移除，测完清理新建记录。
    $seeded = Db::fetch(
        "SELECT c.id FROM claims c JOIN users u ON u.id = c.user_id WHERE c.status = 'open' AND u.email = ?",
        ['claimant@example.com']
    );
    $tokenUser = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'claimant@example.com', 'password' => 'Demo@123456']));
    check($tokenUser !== '', '前置：普通用户应可登录');

    if ($seeded !== null) {
        Db::query('DELETE FROM claims WHERE id = ?', [$seeded['id']]);
    }
    try {
        $claim = api('POST', '/api/v1/memorials/zhang-mingyuan/claim', ['relationship' => '亲属', 'reason' => '我是逝者亲属，申请加入纪念馆共同维护生平与照片，请平台核验。'], $tokenUser);
        check($claim['status'] === 201, '申领应入库（201）');

        $dup = api('POST', '/api/v1/memorials/zhang-mingyuan/claim', ['relationship' => '亲属', 'reason' => '我是逝者亲属，重复提交的第二条申领说明。'], $tokenUser);
        check($dup['status'] === 422, '重复申领应 422');

        $owner = api('POST', '/api/v1/memorials/zhang-mingyuan/claim', ['relationship' => '亲属', 'reason' => '我是馆主本人，再次提交申领不应被受理。'], $GLOBALS['apiToken'] ?? '');
        check($owner['status'] === 422, '馆主重复申领应被拒绝');

        $wrong = api('POST', '/api/v1/memorials/not-exist/claim', ['relationship' => '亲属', 'reason' => '一个不存在的纪念馆链接，提交申领应返回 404。'], $tokenUser);
        check($wrong['status'] === 404, '不存在的馆申领应 404');
    } finally {
        // 测完清理：删除本用例新建的申领
        Db::query("DELETE FROM claims WHERE status = 'open' AND user_id = (SELECT id FROM users WHERE email = ? LIMIT 1)", ['claimant@example.com']);
    }
});

test('manage 创建一律私密草稿', function (): void {
    $admin = api('POST', '/api/v1/auth/token', ['email' => 'admin@example.com', 'password' => 'TestAdmin@123']);
    check($admin['status'] === 200, '前置：管理员应可登录');
    $token = api_token_of($admin);
    $before = db_count('memorials');
    $created = api('POST', '/api/v1/manage/memorials', [
        'full_name' => 'API 自检逝者',
        'gender' => 'unknown',
        'title' => 'API 自检纪念馆',
        'visibility' => 'public', // 故意传公开，应被强制改写为 private
    ], $token);
    check($created['status'] === 201, '创建应成功');
    check(db_count('memorials') === $before + 1, '应入库一馆');
    $slug = (string) ($created['data']['data']['slug'] ?? '');
    check($slug !== '', '应返回新馆 slug');
    $row = Db::fetch('SELECT id, visibility, status FROM memorials WHERE slug = ?', [$slug]);
    try {
        check($row !== null, '应能查到新馆');
        check(($row['visibility'] ?? '') === 'private' && ($row['status'] ?? '') !== 'published', 'API 创建不得直接公开');
        $stranger = api('GET', '/api/v1/memorials/' . $slug);
        check($stranger['status'] === 404, '未核验新馆对访客应 404');
    } finally {
        if ($row !== null) {
            Db::query('DELETE FROM messages WHERE memorial_id = ?', [$row['id']]);
            Db::query('DELETE FROM tributes WHERE memorial_id = ?', [$row['id']]);
            Db::query('DELETE FROM memorial_members WHERE memorial_id = ?', [$row['id']]);
            Db::query('DELETE FROM memorials WHERE id = ?', [$row['id']]);
        }
    }
    check(db_count('memorials') === $before, '测试馆应清理干净');
});

test('成员管理：越权 403、馆不存在 404、未登录 401', function (): void {
    $memorialId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['zhang-mingyuan']);
    check($memorialId > 0, '前置：演示纪念馆应存在');

    $familyToken = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'family@example.com', 'password' => 'Demo@123456']));
    check($familyToken !== '', '前置：editor 应可登录');
    $ownerMemberId = (int) Db::value(
        'SELECT mm.id FROM memorial_members mm JOIN users u ON u.id = mm.user_id WHERE mm.memorial_id = ? AND u.email = ? LIMIT 1',
        [$memorialId, 'demo@example.com']
    );
    check($ownerMemberId > 0, '前置：馆主成员记录应存在');

    $list = api('GET', '/api/v1/manage/memorials/' . $memorialId . '/members', null, $familyToken);
    check($list['status'] === 403, 'editor 查看成员列表应 403');
    check(($list['data']['error']['code'] ?? '') === 'forbidden', '越权错误码应为 forbidden');

    $invite = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', ['role' => 'viewer'], $familyToken);
    check($invite['status'] === 403, 'editor 邀请应 403');

    $role = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $ownerMemberId . '/role', ['role' => 'viewer'], $familyToken);
    check($role['status'] === 403, 'editor 改角色应 403');

    $remove = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $ownerMemberId . '/remove', [], $familyToken);
    check($remove['status'] === 403, 'editor 移除成员应 403');

    $revoke = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/invitations/999999/revoke', [], $familyToken);
    check($revoke['status'] === 403, 'editor 作废邀请应 403');

    $ownerToken = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']));
    $missing = api('GET', '/api/v1/manage/memorials/99999/members', null, $ownerToken);
    check($missing['status'] === 404, '不存在的馆应 404');
    check(($missing['data']['error']['code'] ?? '') === 'not_found', '不存在馆错误码应为 not_found');

    $guest = api('GET', '/api/v1/manage/memorials/' . $memorialId . '/members');
    check($guest['status'] === 401, '未携带 token 应 401');
    check(($guest['data']['error']['code'] ?? '') === 'unauthorized', '未登录错误码应为 unauthorized');
});

test('成员管理：馆主查看/邀请/角色/移除/作废', function (): void {
    $memorialId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['zhang-mingyuan']);
    check($memorialId > 0, '前置：演示纪念馆应存在');
    $ownerToken = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']));
    check($ownerToken !== '', '前置：馆主应可登录');

    $ownerMemberId = (int) Db::value(
        'SELECT mm.id FROM memorial_members mm JOIN users u ON u.id = mm.user_id WHERE mm.memorial_id = ? AND u.email = ? LIMIT 1',
        [$memorialId, 'demo@example.com']
    );
    $familyMemberId = (int) Db::value(
        'SELECT mm.id FROM memorial_members mm JOIN users u ON u.id = mm.user_id WHERE mm.memorial_id = ? AND u.email = ? LIMIT 1',
        [$memorialId, 'family@example.com']
    );
    check($ownerMemberId > 0 && $familyMemberId > 0, '前置：成员记录应存在');

    $email = 'api-member-' . getmypid() . '@example.com';
    try {
        $list = api('GET', '/api/v1/manage/memorials/' . $memorialId . '/members', null, $ownerToken);
        check($list['status'] === 200, '馆主查看成员列表应 200');
        $roles = array_column($list['data']['data']['items'] ?? [], 'role');
        check(in_array('owner', $roles, true), '成员列表应含 owner 角色');
        check(is_array($list['data']['data']['pending'] ?? null), '应返回 pending 数组');
        $ownerRow = null;
        foreach ($list['data']['data']['items'] ?? [] as $item) {
            if ((int) $item['member_id'] === $ownerMemberId) {
                $ownerRow = $item;
                break;
            }
        }
        check($ownerRow !== null, '成员列表应含馆主记录');
        check(($ownerRow['joined_at'] ?? '') !== '', '成员应含 joined_at');
        check(str_contains((string) $ownerRow['email'], '***@'), '成员邮箱应打码');

        $invite = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', ['email' => $email, 'role' => 'viewer'], $ownerToken);
        check($invite['status'] === 201, '邀请应 201');
        $invitationId = (int) ($invite['data']['data']['invitation_id'] ?? 0);
        check($invitationId > 0, '应返回 invitation_id');
        check(str_contains((string) ($invite['data']['data']['invite_url'] ?? ''), '/invitations/'), 'invite_url 应含 /invitations/');
        check(($invite['data']['data']['expires_at'] ?? '') !== '', '应返回 expires_at');

        $dup = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', ['email' => $email, 'role' => 'viewer'], $ownerToken);
        check($dup['status'] === 422, '重复邀请同一邮箱应 422');
        check(($dup['data']['error']['code'] ?? '') === 'validation_failed', '重复邀请错误码应为 validation_failed');

        $withPending = api('GET', '/api/v1/manage/memorials/' . $memorialId . '/members', null, $ownerToken);
        $pendingIds = array_column($withPending['data']['data']['pending'] ?? [], 'invitation_id');
        check(in_array($invitationId, array_map('intval', $pendingIds), true), 'pending 应含新建邀请');

        $revoke = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/invitations/' . $invitationId . '/revoke', [], $ownerToken);
        check($revoke['status'] === 200 && ($revoke['data']['data']['revoked'] ?? false) === true, '作废邀请应 200 revoked=true');
        $revokeAgain = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/invitations/' . $invitationId . '/revoke', [], $ownerToken);
        check($revokeAgain['status'] === 404, '重复作废应 404');

        $toViewer = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $familyMemberId . '/role', ['role' => 'viewer'], $ownerToken);
        check($toViewer['status'] === 200 && ($toViewer['data']['data']['role'] ?? '') === 'viewer', '改角色 viewer 应 200');
        $toEditor = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $familyMemberId . '/role', ['role' => 'editor'], $ownerToken);
        check($toEditor['status'] === 200 && ($toEditor['data']['data']['role'] ?? '') === 'editor', '改回 editor 应 200');

        $selfRemove = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $ownerMemberId . '/remove', [], $ownerToken);
        check($selfRemove['status'] === 422, '馆主对自己移除应 422');

        $ownerRole = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $ownerMemberId . '/role', ['role' => 'editor'], $ownerToken);
        check($ownerRole['status'] === 404, '修改 owner 角色应 404');

        $ownerRemove = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/' . $ownerMemberId . '/remove', [], $ownerToken);
        check($ownerRemove['status'] === 422, '移除 owner 成员应 422');
    } finally {
        // 清理邀请并恢复 editor 角色，保证用例可重复执行且不破坏后续依赖。
        Db::query('DELETE FROM invitations WHERE memorial_id = ? AND email = ?', [$memorialId, $email]);
        if ($familyMemberId > 0) {
            Db::query("UPDATE memorial_members SET status = 'active', role = 'editor' WHERE id = ?", [$familyMemberId]);
        }
    }
});

test('API 举报与目标可见性', function (): void {
    $token = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']));
    check($token !== '', '前置：登录应成功');
    $memorialId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['zhang-mingyuan']);
    check($memorialId > 0, '前置：公开馆应存在');

    $before = db_count('reports');
    $created = api('POST', '/api/v1/reports', [
        'target_type' => 'memorial',
        'target_id' => $memorialId,
        'reason' => 'living_person',
        'detail' => 'API 举报通道自检：疑似活人被祭拜，请平台核实处理。',
    ], $token);
    check($created['status'] === 201, '举报应 201');
    check(($created['data']['data']['priority'] ?? '') === 'urgent', '活人被祭拜应分级 urgent');
    check(db_count('reports') === $before + 1, '举报应入库');
    $reportId = (int) ($created['data']['data']['id'] ?? 0);
    check($reportId > 0, '应返回举报编号');
    $tempMemorialId = 0;

    try {
        $badReason = api('POST', '/api/v1/reports', [
            'target_type' => 'memorial',
            'target_id' => $memorialId,
            'reason' => 'not-a-reason',
            'detail' => str_repeat('测', 15),
        ], $token);
        check($badReason['status'] === 422, '非法 reason 应 422');
        check(($badReason['data']['error']['code'] ?? '') === 'validation_failed', '错误码应为 validation_failed');
        check(isset($badReason['data']['error']['fields']['reason']), '应返回 reason 字段错误');

        $short = api('POST', '/api/v1/reports', [
            'target_type' => 'memorial',
            'target_id' => $memorialId,
            'reason' => 'privacy',
            'detail' => '太短',
        ], $token);
        check($short['status'] === 422, '详情过短应 422');

        $privateId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['memory-11bbb2ba']);
        if ($privateId <= 0) {
            // 测试库无该私密馆时，现建一座私密草稿（馆主可先看，他人不可见）。
            $temp = api('POST', '/api/v1/manage/memorials', [
                'full_name' => '举报可见性自检',
                'gender' => 'unknown',
                'title' => '举报可见性自检',
            ], $token);
            check($temp['status'] === 201, '前置：临时私密馆应创建成功');
            $privateId = (int) ($temp['data']['data']['id'] ?? 0);
            $tempMemorialId = $privateId;
        }
        check($privateId > 0, '前置：私密馆应存在');
        $familyToken = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'family@example.com', 'password' => 'Demo@123456']));
        check($familyToken !== '', '前置：非成员账号应可登录');
        $private = api('POST', '/api/v1/reports', [
            'target_type' => 'memorial',
            'target_id' => $privateId,
            'reason' => 'privacy',
            'detail' => str_repeat('测', 15),
        ], $familyToken);
        check($private['status'] === 404, '私密馆对无权者举报应 404');
        check(($private['data']['error']['code'] ?? '') === 'not_found', '私密馆错误码应为 not_found');

        $missing = api('POST', '/api/v1/reports', [
            'target_type' => 'article',
            'target_id' => 99999999,
            'reason' => 'other',
            'detail' => str_repeat('测', 15),
        ], $token);
        check($missing['status'] === 404, '不存在的目标应 404');

        $guest = api('POST', '/api/v1/reports', [
            'target_type' => 'memorial',
            'target_id' => $memorialId,
            'reason' => 'other',
            'detail' => str_repeat('测', 15),
        ]);
        check($guest['status'] === 401, '未登录举报应 401');
    } finally {
        if ($reportId > 0) {
            Db::query('DELETE FROM reports WHERE id = ?', [$reportId]);
        }
        if ($tempMemorialId > 0) {
            Db::query('DELETE FROM memorial_members WHERE memorial_id = ?', [$tempMemorialId]);
            Db::query('DELETE FROM memorials WHERE id = ?', [$tempMemorialId]);
        }
    }
    check(db_count('reports') === $before, '测试举报应清理干净');
});

echo "\n[管理端 2FA]\n";

$cleanupTotp = static function (string $email): void {
    $account = \App\Models\User::findByEmail($email);
    if ($account !== null) {
        Db::update('users', ['totp_secret' => null, 'totp_enabled' => 0], 'id = ?', [(int) $account['id']]);
    }
};

test('TOTP 算法自洽与 RFC 6238 向量', function (): void {
    $secret = Totp::generateSecret();
    check(preg_match('/^[A-Z2-7]{32}$/', $secret) === 1, '生成的 Base32 密钥应为 32 个字符');

    // RFC 6238 SHA1 测试密钥（ASCII "12345678901234567890"）在 t=59 的 8 位向量为 94287082。
    check(Totp::at('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59) === '287082', 'RFC 6238 向量不匹配');

    $now = time();
    $code = Totp::at($secret, $now);
    check(preg_match('/^\d{6}$/', $code) === 1, '校验码应为 6 位数字');
    check(Totp::verifyCode($secret, $code, 0), '当前时间片应通过');
    check(Totp::verifyCode($secret, Totp::at($secret, $now - 30)), '允许前 1 个时间片');
    check(Totp::verifyCode($secret, Totp::at($secret, $now + 30)), '允许后 1 个时间片');
    check(!Totp::verifyCode($secret, Totp::at($secret, $now - 90)), '超出窗口应拒绝');
    check(!Totp::verifyCode($secret, 'abcdef'), '非数字应拒绝');
    check(!Totp::verifyCode('not-base32', '123456'), '非法密钥应拒绝');

    $uri = Totp::otpauthUri('admin@example.com', $secret);
    check(str_starts_with($uri, 'otpauth://totp/' . rawurlencode('云叙台:admin@example.com') . '?'), 'otpauth 标签编码不正确');
    check_contains($uri, 'secret=' . $secret, 'otpauth 缺少密钥');
    check_contains($uri, 'issuer=云叙台', 'otpauth 缺少发行方');
    check_contains($uri, 'digits=6', 'otpauth 位数不正确');
    check_contains($uri, 'period=30', 'otpauth 周期不正确');

    foreach (['totp_secret', 'totp_enabled'] as $column) {
        check(
            (int) Db::value(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['users', $column]
            ) === 1,
            'users 表缺少 ' . $column
        );
    }
});

test('未启用 2FA 的管理员登录行为不变', function () use ($cleanupTotp): void {
    $cleanupTotp('admin@example.com');
    login('admin@example.com', 'TestAdmin@123');
    $response = http('GET', '/admin');
    check($response['status'] === 200, '管理员后台不可访问');
    check_contains($response['body'], '运营概览', '后台首页内容异常');
    logout();
});

test('2FA 登录闸门、限流与停用流程', function () use ($cleanupTotp): void {
    global $baseUrl;

    $cleanupTotp('admin@example.com');
    $admin = \App\Models\User::findByEmail('admin@example.com');
    check($admin !== null, '测试管理员不存在');
    $adminId = (int) $admin['id'];
    $secret = Totp::generateSecret();
    Db::update('users', ['totp_secret' => $secret, 'totp_enabled' => 1], 'id = ?', [$adminId]);

    $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1');
    // 测试请求从本机发出，兼容 IPv4/IPv6 与自定义 BASE_URL 主机名。
    // 新限流键由 RateLimitKeys 统一生成（身份与 IP 均哈希），清理必须用同一构造逻辑。
    $limitKeys = [];
    foreach (array_unique([$host, '127.0.0.1', '::1']) as $ip) {
        $limitKeys[] = \App\Core\RateLimitKeys::make('2fa', (string) $adminId, $ip);
    }
    foreach ($limitKeys as $limitKey) {
        RateLimiter::clear($limitKey);
    }

    try {
        reset_session();
        $token = csrf_token('/login');
        $start = http('POST', '/login', [
            '_token' => $token,
            'email' => 'admin@example.com',
            'password' => 'TestAdmin@123',
            'next' => '/admin',
        ], [], false);
        check($start['status'] === 302, '启用 2FA 后密码登录应被拦截，实际 ' . $start['status']);
        check(str_contains($start['location'], '/2fa'), '应跳转 /2fa，实际 ' . $start['location']);

        $page = http('GET', '/2fa');
        check($page['status'] === 200, '2FA 页面不可访问');
        check_contains($page['body'], '动态验证码', '2FA 页面缺少验证码表单');

        $blockedAdmin = http('GET', '/admin', null, [], false);
        check($blockedAdmin['status'] === 302, '未完成 2FA 不应能访问后台');
        check(str_contains($blockedAdmin['location'], '/login'), '未完成 2FA 应回到登录页');

        $token = csrf_token('/2fa');
        $now = time();
        $accepted = [
            Totp::at($secret, $now - 60),
            Totp::at($secret, $now - 30),
            Totp::at($secret, $now),
            Totp::at($secret, $now + 30),
            Totp::at($secret, $now + 60),
        ];
        $wrong = sprintf('%06d', ((int) Totp::at($secret, $now) + 1) % 1000000);
        while (in_array($wrong, $accepted, true)) {
            $wrong = sprintf('%06d', ((int) $wrong + 1) % 1000000);
        }

        $bad = http('POST', '/2fa', ['_token' => $token, 'code' => $wrong]);
        check($bad['status'] === 422, '错误验证码应返回 422，实际 ' . $bad['status']);
        check_contains($bad['body'], '验证码不正确', '错误提示缺失');

        for ($i = 0; $i < 4; $i++) {
            $again = http('POST', '/2fa', ['_token' => $token, 'code' => $wrong]);
            check($again['status'] === 422, '第 ' . ($i + 2) . ' 次错误码应仍为 422');
        }
        $limited = http('POST', '/2fa', ['_token' => $token, 'code' => $wrong]);
        check($limited['status'] === 429, '超过尝试上限应返回 429，实际 ' . $limited['status']);
        check_contains($limited['body'], '尝试次数过多', '限流提示缺失');
        foreach ($limitKeys as $limitKey) {
            RateLimiter::clear($limitKey);
        }

        $token = csrf_token('/2fa');
        $success = http('POST', '/2fa', ['_token' => $token, 'code' => Totp::at($secret, time())]);
        check($success['status'] === 200, '正确验证码登录失败，状态 ' . $success['status']);
        check(effective_path($success['url']) === '/admin', '应回到原 next=/admin，实际 ' . effective_path($success['url']));
        check_contains($success['body'], '运营概览', '登录后后台内容异常');

        $accountPage = http('GET', '/account?tab=2fa');
        check($accountPage['status'] === 200, '二次验证设置页不可访问');
        check_contains($accountPage['body'], '二次验证已启用', '启用状态未展示');

        $token = csrf_token('/account?tab=2fa');
        $disabled = http('POST', '/account/totp/disable', ['_token' => $token, 'code' => Totp::at($secret, time())]);
        check($disabled['status'] === 200, '停用 2FA 失败，状态 ' . $disabled['status']);
        check_contains($disabled['body'], '二次验证已停用', '停用成功提示缺失');

        $fresh = \App\Models\User::find($adminId);
        check((int) $fresh['totp_enabled'] === 0, '停用后 totp_enabled 应为 0');
        check($fresh['totp_secret'] === null, '停用后 totp_secret 应清空');

        logout();
        login('admin@example.com', 'TestAdmin@123');
        $after = http('GET', '/admin');
        check($after['status'] === 200, '停用 2FA 后管理员应可正常登录');
    } finally {
        $cleanupTotp('admin@example.com');
        foreach ($limitKeys as $limitKey) {
            RateLimiter::clear($limitKey);
        }
        reset_session();
    }
});

test('管理员重置他人 2FA 与权限边界', function () use ($cleanupTotp): void {
    $cleanupTotp('demo@example.com');
    $demo = \App\Models\User::findByEmail('demo@example.com');
    check($demo !== null, '演示账号不存在');
    $demoId = (int) $demo['id'];

    try {
        login('demo@example.com', 'Demo@123456');
        $token = csrf_token('/account?tab=2fa');
        $forbidden = http('POST', '/admin/users/' . $demoId . '/totp/reset', ['_token' => $token], [], false);
        check($forbidden['status'] === 403, '普通用户调用重置端点应 403，实际 ' . $forbidden['status']);

        login('admin@example.com', 'TestAdmin@123');
        $secret = Totp::generateSecret();
        Db::update('users', ['totp_secret' => $secret, 'totp_enabled' => 1], 'id = ?', [$demoId]);

        $list = http('GET', '/admin/users?q=demo%40example.com');
        check($list['status'] === 200, '用户列表不可访问');
        check_contains($list['body'], '已启用', '用户列表未展示 2FA 状态');
        check_contains($list['body'], '重置二次验证', '用户列表缺少重置入口');

        $token = csrf_token('/admin/users');
        $reset = http('POST', '/admin/users/' . $demoId . '/totp/reset', ['_token' => $token]);
        check($reset['status'] === 200, '重置 2FA 失败，状态 ' . $reset['status']);
        check(effective_path($reset['url']) === '/admin/users', '重置后应回到用户列表');
        check_contains($reset['body'], '已重置', '重置成功提示缺失');

        $fresh = \App\Models\User::find($demoId);
        check((int) $fresh['totp_enabled'] === 0, '重置后 totp_enabled 应为 0');
        check($fresh['totp_secret'] === null, '重置后 totp_secret 应清空');

        $audit = Db::fetch(
            "SELECT meta FROM audit_logs WHERE action = 'totp.reset' AND target_type = 'user' AND target_id = ? ORDER BY id DESC LIMIT 1",
            [$demoId]
        );
        check($audit !== null, '重置操作应写审计');
        check(!str_contains((string) $audit['meta'], $secret), '审计日志不得包含 TOTP 密钥');
    } finally {
        $cleanupTotp('demo@example.com');
        $cleanupTotp('admin@example.com');
        logout();
    }
});

echo "\n[KV 与 Redis]\n";

test('RESP 客户端错误响应抛出 RuntimeException', function (): void {
    $server = new MockRespServer();
    $client = new RespClient($server->url(), static fn (): mixed => $server->pump());
    try {
        check($client->command('PING') === 'PONG', 'PING 应返回 PONG');
        $threw = false;
        try {
            $client->command('NOSUCH');
        } catch (RuntimeException $e) {
            $threw = true;
            check_contains($e->getMessage(), 'Redis 错误', '错误响应应带 Redis 错误前缀');
        }
        check($threw, '服务端错误响应应抛出 RuntimeException');
    } finally {
        $client->close();
        $server->close();
    }
});

test('RedisCounter 计数/TTL/重置（mock Redis）', function (): void {
    $server = new MockRespServer();
    $client = new RespClient($server->url(), static fn (): mixed => $server->pump());
    try {
        $counter = new RedisCounter($client, 'test:counter:');

        check($counter->count('alpha') === 0, '未命中计数应为 0');
        check($counter->increment('alpha', 60) === 1, '首次计数应为 1');
        check($counter->increment('alpha', 60) === 2, '第二次计数应为 2');
        check($counter->count('alpha') === 2, 'GET 应返回当前计数 2');

        $ttl = $counter->ttl('alpha');
        check($ttl > 0 && $ttl <= 60, 'TTL 应落在 60 秒窗口内');

        $commands = $server->commands();
        $incr = array_values(array_filter($commands, static fn (array $c): bool => strtoupper($c[0]) === 'INCR'));
        $expire = array_values(array_filter($commands, static fn (array $c): bool => strtoupper($c[0]) === 'EXPIRE'));
        check(count($incr) === 2, '应收到两次 INCR');
        check(count($expire) === 1, '只有首次计数应 EXPIRE，避免窗口被反复续期');

        $counter->reset('alpha');
        check($counter->count('alpha') === 0, 'DEL 后计数应归零');
        check($counter->ttl('alpha') === -1, '不存在的键 TTL 应为 -1');
    } finally {
        $client->close();
        $server->close();
    }
});

test('RedisSessionHandler 读写销毁往返（mock Redis）', function (): void {
    $server = new MockRespServer();
    $client = new RespClient($server->url(), static fn (): mixed => $server->pump());
    try {
        $handler = new RedisSessionHandler($client, 'test:session:');
        $payload = 'uid|i:42;name|s:9:"云叙台测试";';

        check($handler->open(sys_get_temp_dir(), 'PHPSESSID') === true, 'open 应为 true');
        check($handler->write('abc123', $payload) === true, 'write 应为 true');
        check($handler->read('abc123') === $payload, 'read 应原样返回会话数据');

        $setex = array_values(array_filter(
            $server->commands(),
            static fn (array $c): bool => strtoupper($c[0]) === 'SETEX'
        ));
        check(count($setex) === 1, '会话写入应使用 SETEX');
        $expectedTtl = max(1, (int) (ini_get('session.gc_maxlifetime') ?: 1440));
        check((int) ($setex[0][2] ?? 0) === $expectedTtl, 'SETEX TTL 应等于 session.gc_maxlifetime');

        check($handler->destroy('abc123') === true, 'destroy 应为 true');
        check($handler->read('abc123') === '', '销毁后 read 应返回空串');
        check($handler->gc(1440) === 0, 'gc 应为无副作用返回 0');
        check($handler->close() === true, 'close 应为 true');
    } finally {
        $client->close();
        $server->close();
    }
});

test('RateLimiter Redis 驱动语义一致', function (): void {
    $server = new MockRespServer();
    $client = new RespClient($server->url(), static fn (): mixed => $server->pump());
    Kv::setCounter(new RedisCounter($client, 'test:rate:'));

    $key = 'kv-rate-redis-' . getmypid();
    try {
        check(RateLimiter::hit($key, 3, 60) === true, '第 1 次应允许');
        check(RateLimiter::hit($key, 3, 60) === true, '第 2 次应允许');
        check(RateLimiter::hit($key, 3, 60) === true, '第 3 次应允许');
        check(RateLimiter::hit($key, 3, 60) === false, '第 4 次应被阻止');
        check(RateLimiter::blocked($key, 3) === true, '连续命中后应处于阻止状态');

        RateLimiter::clear($key);
        check(RateLimiter::blocked($key, 3) === false, 'clear 后应恢复');
    } finally {
        Kv::setCounter(null);
        Kv::reset();
        $client->close();
        $server->close();
    }
});

test('RateLimiter MySQL 驱动回归', function (): void {
    Kv::setCounter(new MysqlCounter());
    $key = 'kv-rate-mysql-' . getmypid();

    try {
        check(RateLimiter::hit($key, 3, 60) === true, '第 1 次应允许');
        check(RateLimiter::hit($key, 3, 60) === true, '第 2 次应允许');
        check(RateLimiter::hit($key, 3, 60) === true, '第 3 次应允许');
        check(RateLimiter::hit($key, 3, 60) === false, '第 4 次应被阻止');
        check(RateLimiter::blocked($key, 3) === true, '连续命中后应处于阻止状态');

        RateLimiter::clear($key);
        check(RateLimiter::blocked($key, 3) === false, 'clear 后应恢复');
    } finally {
        RateLimiter::clear($key);
        Kv::setCounter(null);
        Kv::reset();
    }
});

test('无 REDIS_URL 时驱动回退 MySQL', function (): void {
    $saved = $GLOBALS['config']['kv']['redis_url'] ?? '';
    $GLOBALS['config']['kv']['redis_url'] = '';
    Kv::reset();

    try {
        check(Kv::counter() instanceof MysqlCounter, '未配置 Redis 时应使用 MySQL 驱动');
    } finally {
        $GLOBALS['config']['kv']['redis_url'] = $saved;
        Kv::reset();
    }
});

echo "\n[邮件通知]\n";

test('Notify log 驱动生成可解析邮件', function (): void {
    $path = sys_get_temp_dir() . '/jingsi-notify-test-' . getmypid() . '.log';
    @unlink($path);
    Notify::configure(['driver' => 'log', 'log_path' => $path]);

    try {
        $body = "第一行正文\n第二行：云叙台";
        check(Notify::send('notify@example.com', '云叙台·邀请测试', $body), '合法邮箱应发送成功');

        clearstatcache();
        check(is_file($path), '应写入日志文件');
        $raw = (string) file_get_contents($path);
        check_contains($raw, 'To: notify@example.com', '缺少 To 头');
        check_contains($raw, 'Message-ID: <', '缺少 Message-ID');
        check_contains($raw, 'MIME-Version: 1.0', '缺少 MIME-Version');
        check_contains($raw, 'Content-Type: text/plain; charset=utf-8', '缺少 Content-Type');
        check_contains($raw, 'Content-Transfer-Encoding: base64', '缺少传输编码');

        check(preg_match('/^Subject: (.+)$/m', $raw, $matches) === 1, '缺少 Subject 头');
        check(mb_decode_mimeheader(trim($matches[1])) === '云叙台·邀请测试', 'Subject 应可按 RFC2047 解码');

        $parts = preg_split('/\r?\n\r?\n/', trim($raw), 2);
        check(count($parts) === 2, '缺少头部与正文分隔');
        foreach (preg_split('/\r?\n/', trim($parts[1])) ?: [] as $line) {
            check(strlen(trim($line)) <= 76, 'base64 正文单行不应超过 76 字符');
        }
        $decoded = base64_decode((string) preg_replace('/\s+/', '', $parts[1]), true);
        check($decoded === $body, '正文 base64 解码后应与原文一致');
    } finally {
        Notify::reset();
        @unlink($path);
    }
});

test('Notify 非法目标与 none 驱动不写邮件', function (): void {
    $path = sys_get_temp_dir() . '/jingsi-notify-invalid-' . getmypid() . '.log';
    @unlink($path);
    Notify::configure(['driver' => 'log', 'log_path' => $path]);

    try {
        check(Notify::send(null, '主题', '正文') === false, 'NULL 目标应返回 false');
        check(Notify::send('', '主题', '正文') === false, '空目标应返回 false');
        check(Notify::send('not-an-email', '主题', '正文') === false, '非法邮箱应返回 false');
        clearstatcache();
        check(!is_file($path), '非法目标不应创建日志文件');

        Notify::configure(['driver' => 'none', 'log_path' => $path]);
        check(Notify::send('notify@example.com', '主题', '正文') === false, 'none 驱动应恒为 false');
        clearstatcache();
        check(!is_file($path), 'none 驱动不应写日志');
    } finally {
        Notify::reset();
        @unlink($path);
    }
});

test('Notify 日志目录不存在时安全失败', function (): void {
    $dir = sys_get_temp_dir() . '/jingsi-notify-missing-' . getmypid();
    $path = $dir . '/mail.log';
    Notify::configure(['driver' => 'log', 'log_path' => $path]);

    try {
        check(Notify::send('notify@example.com', '主题', '正文') === false, '目录不存在应返回 false');
        check(!is_file($path), '目录不存在时不应写文件');
    } finally {
        Notify::reset();
        @unlink($path);
        @rmdir($dir);
    }
});

test('API 邀请写入邮件日志，重复邀请不新增', function (): void {
    $memorialId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['zhang-mingyuan']);
    check($memorialId > 0, '前置：演示纪念馆应存在');

    $token = api_token_of(api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']));
    check($token !== '', '前置：馆主应可登录');

    $email = 'api-member-' . getmypid() . '@example.com';
    $logPath = (string) config('mail.log_path');
    $before = is_file($logPath) ? (string) file_get_contents($logPath) : null;
    $decodeMails = static function (string $raw): array {
        $mails = [];
        foreach (preg_split('/(?=^Date: )/m', trim($raw)) ?: [] as $chunk) {
            $parts = preg_split('/\r?\n\r?\n/', $chunk, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $decoded = base64_decode((string) preg_replace('/\s+/', '', $parts[1]), true);
            $mails[] = ['headers' => $parts[0], 'body' => $decoded === false ? '' : $decoded];
        }
        return $mails;
    };

    try {
        $invite = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', [
            'email' => $email,
            'role' => 'editor',
        ], $token);
        check($invite['status'] === 201, '邀请应 201，实际 ' . $invite['status']);
        $inviteUrl = (string) ($invite['data']['data']['invite_url'] ?? '');
        check(str_contains($inviteUrl, '/invitations/'), '响应应含邀请链接');

        clearstatcache();
        $after = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        $mailsAfter = $decodeMails($after);
        $mailsBefore = $decodeMails((string) $before);
        check(count($mailsAfter) === count($mailsBefore) + 1, '邀请成功应新增一封邮件');
        $mail = $mailsAfter[count($mailsAfter) - 1];
        check_contains($mail['headers'], 'To: ' . $email, '邮件应投递给受邀邮箱');
        check_contains($mail['headers'], 'Subject:', '邮件应含主题');
        check_contains($mail['body'], $inviteUrl, '邮件正文应含邀请链接');
        check_contains($mail['body'], '7 天', '邮件正文应说明有效期');

        $dup = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', [
            'email' => $email,
            'role' => 'editor',
        ], $token);
        check($dup['status'] === 422, '重复邀请应 422');
        clearstatcache();
        $afterDup = is_file($logPath) ? (string) file_get_contents($logPath) : '';
        check(count($decodeMails($afterDup)) === count($mailsAfter), '重复邀请不应新增邮件');
    } finally {
        if ($before === null) {
            @unlink($logPath);
        } else {
            @file_put_contents($logPath, $before);
        }
        Db::query('DELETE FROM invitations WHERE memorial_id = ? AND email = ?', [$memorialId, $email]);
        Db::query('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        Notify::reset();
    }
});

test('SMTP 不可达时 Notify 返回 false 且不抛错', function (): void {
    Notify::configure([
        'driver' => 'smtp',
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1,
        'smtp_timeout' => 1,
        'smtp_encryption' => 'none',
        'smtp_username' => '',
        'smtp_password' => '',
    ]);

    try {
        $start = microtime(true);
        $result = Notify::send('notify@example.com', 'SMTP 失败测试', '正文不应阻断请求');
        $elapsed = microtime(true) - $start;

        check($result === false, 'SMTP 失败应返回 false');
        check($elapsed < 5.0, '应在超时预算内快速失败，实际 ' . round($elapsed, 2) . ' 秒');
    } finally {
        Notify::reset();
    }
});

test('AI 收费订单（收款确认制）', function (): void {
    // 1) 管理员在 AI 设置页开启收费
    login('admin@example.com', 'TestAdmin@123');
    $token = csrf_token('/admin/ai-settings');
    $save = http('POST', '/admin/ai-settings', [
        '_token' => $token,
        'enabled' => '1',
        'provider' => 'demo',
        'text_model' => '',
        'image_model' => '',
        'timeout' => '120',
        'daily_limit' => '10',
        'sync' => '1',
        'charging' => '1',
    ], [], false);
    check($save['status'] === 302, '开启收费应成功，实际 ' . $save['status']);
    check((string) Db::value("SELECT value FROM platform_settings WHERE name = 'ai.charging'") === '1', '充电开关未落库');

    // 2) 馆主提交修图任务 → 应生成 pending 订单，而非立刻建 job
    $expectedCents = (int) Db::value("SELECT price_cents FROM ai_services WHERE slug = 'photo_restore'");
    login('demo@example.com', 'Demo@123456');
    $before = (int) Db::value('SELECT COUNT(*) FROM ai_jobs');
    $photo = Db::fetch("SELECT id FROM media WHERE memorial_id = 1 AND kind = 'photo' ORDER BY id ASC LIMIT 1");
    $tokenJob = csrf_token('/manage/memorials/1/ai');
    $res = http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $tokenJob,
        'service' => 'photo_restore',
        'media_id' => (string) $photo['id'],
        'consent' => '1',
    ]);
    check($res['status'] === 200, '订单流程任务提交失败，实际 ' . $res['status']);
    $order = Db::fetch("SELECT * FROM ai_orders WHERE service_slug = 'photo_restore' ORDER BY id DESC LIMIT 1");
    check($order !== null && $order['status'] === 'pending', '未生成 pending 订单');
    check((int) $order['price_cents'] === $expectedCents, '订单价格快照错误：' . ($order['price_cents'] ?? '') . ' 期望 ' . $expectedCents);
    check((int) Db::value('SELECT COUNT(*) FROM ai_jobs') === $before, '付费订单不应立即创建任务');
    $studios = http('GET', '/manage/memorials/1/ai');
    check_contains((string) $studios['body'], '收款确认制已开启', 'studio 页缺收费提示');
    check_contains((string) $studios['body'], '待平台确认', 'studio 页缺订单状态');

    // 3) 管理员在 AI 订单页确认收款 → 创建 job（cost=订单价）且订单 paid
    login('admin@example.com', 'TestAdmin@123');
    $orders = http('GET', '/admin/ai-orders');
    check($orders['status'] === 200, '后台订单页不可访问');
    check_contains((string) $orders['body'], 'photo_restore', '订单页未列出订单');
    $tokenOrders = csrf_token('/admin/ai-orders');
    $confirm = http('POST', '/admin/ai-orders/' . (int) $order['id'] . '/confirm', [
        '_token' => $tokenOrders,
        'payment_ref' => '测试收款单',
    ], [], false);
    check($confirm['status'] === 302, '确认收款应跳转，实际 ' . $confirm['status']);
    $order = Db::fetch('SELECT * FROM ai_orders WHERE id = ?', [(int) $order['id']]);
    check($order !== null && $order['status'] === 'paid', '订单未变 paid');
    check($order['task_id'] !== null, '订单未关联任务');
    check((string) $order['payment_ref'] === '测试收款单', '收款单号未保存');
    $job = Db::fetch("SELECT * FROM ai_jobs WHERE id = ?", [(int) $order['task_id']]);
    check($job !== null && (int) $job['cost_cents'] === $expectedCents, '任务金额快照错误');

    // 4) 第二单走驳回：只允许 pending，驳回后不产生任务
    login('demo@example.com', 'Demo@123456');
    $jobsAfterPaid = (int) Db::value('SELECT COUNT(*) FROM ai_jobs');
    $tokenJob = csrf_token('/manage/memorials/1/ai');
    http('POST', '/manage/memorials/1/ai/jobs', [
        '_token' => $tokenJob,
        'service' => 'photo_restore',
        'media_id' => (string) $photo['id'],
        'consent' => '1',
    ]);
    $order2 = Db::fetch("SELECT * FROM ai_orders WHERE service_slug = 'photo_restore' ORDER BY id DESC LIMIT 1");
    check($order2 !== null && (int) $order2['id'] !== (int) $order['id'], '未生成第二单');
    check($order2['status'] === 'pending', '第二单应为 pending');
    check((int) Db::value('SELECT COUNT(*) FROM ai_jobs') === $jobsAfterPaid, '第二单不应建任务');

    login('admin@example.com', 'TestAdmin@123');
    $tokenOrders = csrf_token('/admin/ai-orders');
    $reject = http('POST', '/admin/ai-orders/' . (int) $order2['id'] . '/reject', [
        '_token' => $tokenOrders,
        'note' => '测试驳回',
    ], [], false);
    check($reject['status'] === 302, '驳回应跳转，实际 ' . $reject['status']);
    $order2 = Db::fetch('SELECT * FROM ai_orders WHERE id = ?', [(int) $order2['id']]);
    check($order2 !== null && $order2['status'] === 'rejected', '订单未变 rejected');
    check((int) Db::value('SELECT COUNT(*) FROM ai_jobs') === $jobsAfterPaid, '驳回后不应新增任务');

    // 收尾：清订单 / 任务，收费开关回退免费模式（settings 行一并删除）
    try {
        Db::query("DELETE FROM ai_orders WHERE memorial_id = 1 AND service_slug = 'photo_restore'");
    } finally {
        Db::query('DELETE FROM ai_jobs WHERE memorial_id = 1 AND cost_cents = :cost', ['cost' => $expectedCents]);
        Db::query("DELETE FROM platform_settings WHERE name LIKE 'ai.%'");
        \App\Core\Settings::reset();
    }
});



echo "\n[阶段 B：家族基础服务与权限隔离]\n";

/** 预期的 HttpException 断言 */
function expect_http_error(callable $fn, int $status, string $message): void
{
    try {
        $fn();
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === $status, $message . '（实际状态码 ' . $e->getStatusCode() . '）');
        return;
    }
    throw new RuntimeException($message . '（未抛出异常）');
}

/** 创建测试用户并返回完整用户数组 */
function make_family_user(string $label): array
{
    $email = 'fam-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.test';
    $id = \App\Models\User::create($email, 'FamilyTest@123', '家族测试 ' . $label);
    $user = \App\Models\User::find($id);
    check(is_array($user), '测试用户创建失败');
    return $user;
}

test('2.0 迁移版本记录完整且可重复执行', function (): void {
    // 迁移数量随阶段增加；只校验每个已登记版本与阶段 B 基线版本均为 applied。
    $expected = [
        '20261127_001', '20261127_002', '20261127_003', '20261127_004', '20261127_005', '20261127_006',
    ];
    check(db_count('schema_migrations') >= count($expected), '2.0 迁移记录不应少于阶段 B 基线');
    check(db_count('schema_migrations', "status = 'applied'") === db_count('schema_migrations'), '所有迁移应为 applied');
    foreach ($expected as $version) {
        check(
            (int) \App\Core\Db::value("SELECT COUNT(*) FROM schema_migrations WHERE version = ? AND status = 'applied'", [$version]) === 1,
            '基线迁移缺失或未执行：' . $version
        );
    }
    $checksums = \App\Core\Db::fetchAll('SELECT version, checksum FROM schema_migrations');
    foreach ($checksums as $row) {
        check((string) $row['checksum'] !== '', '迁移 checksum 不应为空');
    }
    // Phase E：逐个比对磁盘迁移文件内容与数据库登记的 checksum，覆盖全部版本而非仅 001-006。
    $files = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
    sort($files);
    $recorded = array_column($checksums, 'checksum');
    check(count($files) === count($recorded), '迁移文件数与数据库登记数不一致');
    foreach ($files as $file) {
        $hash = hash('sha256', (string) file_get_contents($file));
        check(in_array($hash, $recorded, true), '迁移 checksum 与文件不符：' . basename($file));
    }
    foreach (['families', 'family_members', 'family_invitations', 'family_persons', 'person_memorial_links'] as $table) {
        check(
            (int) \App\Core\Db::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) > 0,
            '缺少 2.0 表 ' . $table
        );
    }
});

test('创建家族时 owner 自动成为 active 成员并写审计', function (): void {
    $owner = make_family_user('owner');
    $slug = 'fam-' . bin2hex(random_bytes(4));
    $familyId = \App\Services\FamilyService::create($owner, '测试家族', $slug);
    check($familyId > 0, '家族创建失败');
    check((string) \App\Core\Db::value('SELECT slug FROM families WHERE id = ?', [$familyId]) === $slug, 'slug 未保存');
    check(db_count('family_members', 'family_id = ? AND user_id = ? AND role = ? AND status = ?',
        [$familyId, $owner['id'], 'owner', 'active']) === 1, 'owner 成员记录缺失');
    check(db_count('audit_logs', "action = 'family.create' AND target_type = 'family' AND target_id = ?", [$familyId]) === 1,
        '家族创建未写审计');
    check(\App\Core\Policy::familyRole($familyId, (int) $owner['id']) === 'owner', 'Policy 未识别 owner');
});

test('邀请令牌：错邮箱/撤销/重放均失效，接受后角色生效', function (): void {
    $owner = make_family_user('invite-owner');
    $invitee = make_family_user('invitee');
    $outsider = make_family_user('outsider');
    $familyId = \App\Services\FamilyService::create($owner, '邀请测试家族', 'invite-' . bin2hex(random_bytes(4)), ['privacy' => 'public']);

    $invite = \App\Services\FamilyService::invite($owner, $familyId, (string) $invitee['email'], 'member');
    check($invite['token'] !== '', '邀请令牌为空');
    check(db_count('family_invitations', 'family_id = ? AND status = ?', [$familyId, 'pending']) === 1, '邀请未入库');
    check((string) \App\Core\Db::value('SELECT token_hash FROM family_invitations WHERE family_id = ?', [$familyId]) !== $invite['token'],
        '明文令牌不应入库');
    expect_http_error(static fn () => \App\Services\FamilyService::acceptInvitation($outsider, $invite['token']), 404, '错邮箱不应接受邀请');

    \App\Services\FamilyService::revokeInvitation($owner, (int) \App\Core\Db::value('SELECT id FROM family_invitations WHERE family_id = ?', [$familyId]));
    expect_http_error(static fn () => \App\Services\FamilyService::acceptInvitation($invitee, $invite['token']), 404, '撤销后不应接受邀请');

    $invite2 = \App\Services\FamilyService::invite($owner, $familyId, (string) $invitee['email'], 'genealogy_editor');
    check(\App\Services\FamilyService::acceptInvitation($invitee, $invite2['token']) === $familyId, '接受邀请失败');
    check(\App\Core\Policy::familyRole($familyId, (int) $invitee['id']) === 'genealogy_editor', '角色未生效');
    expect_http_error(static fn () => \App\Services\FamilyService::acceptInvitation($invitee, $invite2['token']), 404, '令牌重放应失败');
    check(\App\Core\Policy::can('person.edit', ['family_id' => $familyId], $invitee), '谱系编辑者应能编辑人物');
    check(!\App\Core\Policy::can('family.manage', ['family_id' => $familyId], $invitee), '普通编辑者不应管理家族');
});

test('跨家族访问一律拒绝且不泄露资源存在性', function (): void {
    $ownerA = make_family_user('cross-a');
    $ownerB = make_family_user('cross-b');
    $familyA = \App\Services\FamilyService::create($ownerA, '家族甲', 'cross-a-' . bin2hex(random_bytes(4)), ['privacy' => 'public']);
    $familyB = \App\Services\FamilyService::create($ownerB, '家族乙', 'cross-b-' . bin2hex(random_bytes(4)), ['privacy' => 'public']);
    $personB = \App\Services\FamilyPersonService::create($ownerB, $familyB, [
        'name' => '乙家族人物', 'is_deceased' => true, 'privacy' => 'public',
    ]);

    check(\App\Core\Policy::can('family.view', ['family_id' => $familyA], $ownerB), '公开家族应允许访客查看');
    check(!\App\Core\Policy::can('family.manage', ['family_id' => $familyA], $ownerB), '跨家族管理应拒绝');
    check(!\App\Core\Policy::canViewResource('person', $personB, (int) $ownerA['id']), '跨家族人物不应可见');
    check(\App\Services\FamilyPersonService::getVisible($ownerA, $personB) === null, '越权人物查询应返回 null');
    expect_http_error(static fn () => \App\Core\Policy::require('family.manage', ['family_id' => $familyA], $ownerB), 404, '越权 require 应抛异常');
    expect_http_error(static fn () => \App\Services\FamilyService::update($ownerB, $familyA, ['name' => '篡改']), 404, '跨家族改家族应拒绝');
    expect_http_error(static fn () => \App\Services\FamilyPersonService::update($ownerA, $familyA, $personB, ['name' => '篡改']), 404, '跨家族改人物应拒绝');
});

test('移除/封禁成员的历史权限立即失效', function (): void {
    $owner = make_family_user('remove-owner');
    $member = make_family_user('remove-member');
    $familyId = \App\Services\FamilyService::create($owner, '成员测试', 'remove-' . bin2hex(random_bytes(4)));
    $invite = \App\Services\FamilyService::invite($owner, $familyId, (string) $member['email'], 'member');
    \App\Services\FamilyService::acceptInvitation($member, $invite['token']);
    check(\App\Core\Policy::can('family.view', ['family_id' => $familyId], $member), '成员应可查看家族');

    \App\Services\FamilyService::removeMember($owner, $familyId, (int) $member['id']);
    check(\App\Core\Policy::familyRole($familyId, (int) $member['id']) === null, '移除后角色应失效');
    check(!\App\Core\Policy::can('family.view', ['family_id' => $familyId], $member), '移除后不应访问');

    expect_http_error(static fn () => \App\Services\FamilyService::leave($owner, $familyId), 403, 'owner 不应直接退出');
});

test('活人资料默认私密，公开创建/更新被拒绝；逝者公开仍须核验', function (): void {
    $owner = make_family_user('person-owner');
    $outsider = make_family_user('person-visitor');
    $familyId = \App\Services\FamilyService::create($owner, '人物测试', 'person-' . bin2hex(random_bytes(4)), ['privacy' => 'public']);

    expect_http_error(static fn () => \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '活人', 'privacy' => 'public', 'is_deceased' => false,
    ]), 422, '活人资料不得直接公开');

    $livingId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '活人', 'privacy' => 'private', 'is_deceased' => false,
    ]);
    check(\App\Services\FamilyPersonService::getVisible($owner, $livingId) !== null, '创建者应可见活人资料');
    check(\App\Services\FamilyPersonService::getVisible($outsider, $livingId) === null, '活人资料不应被访客看到');
    expect_http_error(static fn () => \App\Services\FamilyPersonService::update($owner, $familyId, $livingId, ['privacy' => 'family']), 422, '活人不可改为非私密');

    $deceasedId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '逝者', 'privacy' => 'public', 'is_deceased' => true,
    ]);
    check((string) \App\Core\Db::value('SELECT verification_status FROM family_persons WHERE id = ?', [$deceasedId]) === 'none',
        '新建人物不得自动成为已核验事实');
    check(\App\Services\FamilyPersonService::getVisible($outsider, $deceasedId) === null, '未核验人物不应公开展示');
    \App\Core\Db::update('family_persons', ['verification_status' => 'verified'], 'id = ?', [$deceasedId]);
    \App\Core\Policy::clearCache();
    check(\App\Services\FamilyPersonService::getVisible($outsider, $deceasedId) !== null, '核验后应公开展示');
});

test('角色调整必须写审计且禁止自审式越权', function (): void {
    $owner = make_family_user('audit-owner');
    $member = make_family_user('audit-member');
    $familyId = \App\Services\FamilyService::create($owner, '审计测试', 'audit-' . bin2hex(random_bytes(4)));
    $invite = \App\Services\FamilyService::invite($owner, $familyId, (string) $member['email'], 'member');
    \App\Services\FamilyService::acceptInvitation($member, $invite['token']);
    expect_http_error(static fn () => \App\Services\FamilyService::updateRole($member, $familyId, (int) $member['id'], 'admin'), 404, '成员不能自行提权');
    \App\Services\FamilyService::updateRole($owner, $familyId, (int) $member['id'], 'admin');
    check(\App\Core\Policy::familyRole($familyId, (int) $member['id']) === 'admin', '角色调整未生效');
    check(db_count('audit_logs', "action = 'family.member.role' AND target_id = ?", [$familyId]) === 1, '角色调整未写审计');
    expect_http_error(static fn () => \App\Services\FamilyService::updateRole($owner, $familyId, (int) $owner['id'], 'member'), 403, 'owner 不应被降级');
});


echo "\n[阶段 C：家族空间、人物与谱系]\n";

test('Web 创建家族并访问家族主页与成员页', function (): void {
    $owner = make_family_user('web-family');
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/families/new');
    $response = http('POST', '/families', [
        '_token' => $token, 'name' => '网页测试家族', 'slug' => 'web-family-' . bin2hex(random_bytes(3)),
        'privacy' => 'public', 'description' => '由集成测试创建',
    ]);
    check($response['status'] === 200, '创建家族失败：' . $response['status']);
    check(preg_match('#/families/(\d+)$#', $response['url'], $m) === 1, '创建后未跳转家族主页');
    $familyId = (int) $m[1];
    $show = http('GET', '/families/' . $familyId);
    check_contains($show['body'], '网页测试家族');
    $members = http('GET', '/families/' . $familyId . '/members');
    check_contains($members['body'], '创建邀请');
    check_contains($members['body'], 'name="_token"');
});

test('Web 家族邀请可被受邀邮箱本人接受', function (): void {
    $owner = make_family_user('web-invite-owner');
    $member = make_family_user('web-invite-member');
    $familyId = \App\Services\FamilyService::create($owner, '邀请测试', 'web-invite-' . bin2hex(random_bytes(3)));
    $invite = \App\Services\FamilyService::invite($owner, $familyId, (string) $member['email'], 'genealogy_editor');
    login((string) $member['email'], 'FamilyTest@123');
    $token = csrf_token('/invitations/' . $invite['token']);
    $response = http('POST', '/invitations/' . $invite['token'], ['_token' => $token]);
    check($response['status'] === 200, '接受家族邀请失败');
    check(effective_path($response['url']) === '/families/' . $familyId, '未跳转到家族主页');
    check(\App\Core\Policy::familyRole($familyId, (int) $member['id']) === 'genealogy_editor', '邀请角色未生效');
});

test('Web 新增人物与详情页；活人隐私约束无法通过表单绕过', function (): void {
    $owner = make_family_user('web-person');
    $familyId = \App\Services\FamilyService::create($owner, '人物页面', 'web-person-' . bin2hex(random_bytes(3)), ['privacy' => 'public']);
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/families/' . $familyId . '/persons/new');
    $response = http('POST', '/families/' . $familyId . '/persons', [
        '_token' => $token, 'name' => '网页人物', 'is_deceased' => '1', 'privacy' => 'family',
        'birth_date' => '1930-01-02', 'biography' => '生平资料',
    ]);
    check(preg_match('#/families/' . $familyId . '/persons/(\d+)$#', $response['url'], $m) === 1, '人物创建失败');
    $personId = (int) $m[1];
    $show = http('GET', '/families/' . $familyId . '/persons/' . $personId);
    check_contains($show['body'], '网页人物');
    check_contains($show['body'], '关联纪念馆');
    $token2 = csrf_token('/families/' . $familyId . '/persons/new');
    $bad = http('POST', '/families/' . $familyId . '/persons', [
        '_token' => $token2, 'name' => '活人', 'is_deceased' => '', 'privacy' => 'public',
    ]);
    check($bad['status'] === 422, '活人公开应被拒绝，实际 ' . $bad['status']);
});

test('亲属关系：提交为待审核，确认后进入谱系，撤销后消失', function (): void {
    $owner = make_family_user('rel-owner');
    $familyId = \App\Services\FamilyService::create($owner, '关系测试', 'rel-' . bin2hex(random_bytes(3)), ['privacy' => 'public']);
    $a = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '父亲', 'is_deceased' => 1, 'privacy' => 'public']);
    $b = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '子女', 'is_deceased' => 1, 'privacy' => 'public']);
    Db::update('family_persons', ['verification_status' => 'verified'], 'id = ?', [$a]);
    Db::update('family_persons', ['verification_status' => 'verified'], 'id = ?', [$b]);
    \App\Core\Policy::clearCache();
    $relId = \App\Services\GenealogyService::submit($owner, $familyId, $a, $b, 'parent');
    check((string) \App\Core\Db::value('SELECT status FROM person_relationships WHERE id = ?', [$relId]) === 'pending', '关系不得自动确认');
    $tree = \App\Services\GenealogyService::tree($owner, $familyId);
    check($tree['relations'] === [], '待审核关系不应进入谱系');
    \App\Services\GenealogyService::review($owner, $familyId, $relId, 'confirmed');
    $tree = \App\Services\GenealogyService::tree($owner, $familyId);
    check(count($tree['relations']) === 1, '确认后关系应进入谱系');
    $inverse = \App\Services\GenealogyService::RELATION_TYPES['parent']['inverse'];
    check($inverse === 'child', '反向关系定义错误');
    \App\Services\GenealogyService::revoke($owner, $familyId, $relId);
    $tree = \App\Services\GenealogyService::tree($owner, $familyId);
    check($tree['relations'] === [], '撤销后关系不应显示在谱系');
});

test('关系安全：自关联、重复、血缘循环与跨家族关系均被拒绝', function (): void {
    $owner = make_family_user('rel-safe');
    $familyId = \App\Services\FamilyService::create($owner, '关系安全', 'rel-safe-' . bin2hex(random_bytes(3)));
    $a = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '祖父', 'is_deceased' => 1]);
    $b = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '父亲', 'is_deceased' => 1]);
    $c = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '孙子', 'is_deceased' => 1]);
    expect_http_error(static fn () => \App\Services\GenealogyService::submit($owner, $familyId, $a, $a, 'parent'), 422, '自关联应拒绝');
    $r1 = \App\Services\GenealogyService::submit($owner, $familyId, $a, $b, 'parent');
    \App\Services\GenealogyService::review($owner, $familyId, $r1, 'confirmed');
    expect_http_error(static fn () => \App\Services\GenealogyService::submit($owner, $familyId, $a, $b, 'parent'), 409, '重复关系应拒绝');
    expect_http_error(static fn () => \App\Services\GenealogyService::submit($owner, $familyId, $b, $a, 'parent'), 422, '血缘循环应拒绝');
    $outsider = make_family_user('rel-outsider');
    $other = \App\Services\FamilyService::create($outsider, '外部家族', 'rel-out-' . bin2hex(random_bytes(3)));
    $outsidePerson = \App\Services\FamilyPersonService::create($outsider, $other, ['name' => '外人', 'is_deceased' => 1]);
    expect_http_error(static fn () => \App\Services\GenealogyService::submit($owner, $familyId, $a, $outsidePerson, 'spouse'), 404, '跨家族人物不可建立关系');
});

test('支系创建与分配，重复分配不产生重复关联', function (): void {
    $owner = make_family_user('branch-owner');
    $familyId = \App\Services\FamilyService::create($owner, '支系测试', 'branch-' . bin2hex(random_bytes(3)));
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '支系人物', 'is_deceased' => 1]);
    $branchId = \App\Services\GenealogyService::createBranch($owner, $familyId, '长房', null);
    $childId = \App\Services\GenealogyService::createBranch($owner, $familyId, '长房一支', $branchId);
    \App\Services\GenealogyService::assignBranch($owner, $familyId, $personId, $childId);
    \App\Services\GenealogyService::assignBranch($owner, $familyId, $personId, $childId);
    check(db_count('person_branch_members', 'person_id = ? AND branch_id = ?', [$personId, $childId]) === 1, '支系关联不应重复');
    check(count(\App\Services\GenealogyService::branches($owner, $familyId)) === 2, '支系数目不正确');
});

test('人物合并保留纪念馆关联与审计，重复人物软删除可追溯', function (): void {
    $owner = make_family_user('merge-owner');
    $familyId = \App\Services\FamilyService::create($owner, '合并测试', 'merge-' . bin2hex(random_bytes(3)));
    $primary = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '同名', 'is_deceased' => 1]);
    $duplicate = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '同名', 'is_deceased' => 1]);
    $memorialId = (int) Db::value("SELECT id FROM memorials WHERE status = 'active' LIMIT 1");
    check($memorialId > 0, '缺少可关联的纪念馆');
    Db::insert('memorial_members', [
        'memorial_id' => $memorialId, 'user_id' => (int) $owner['id'], 'role' => 'editor', 'status' => 'active',
    ]);
    \App\Core\Policy::clearCache();
    \App\Services\FamilyPersonService::linkMemorial($owner, $familyId, $duplicate, $memorialId);
    check(db_count('person_memorial_links', 'person_id = ?', [$duplicate]) === 1, '关联未建立');
    \App\Services\GenealogyService::mergePersons($owner, $familyId, $primary, $duplicate);
    check(db_count('person_memorial_links', 'person_id = ?', [$duplicate]) === 0, '关联未迁移');
    check(db_count('person_memorial_links', 'person_id = ?', [$primary]) === 1, '主人物未继承关联');
    check(db_count('person_merge_log', 'duplicate_id = ?', [$duplicate]) === 1, '缺少合并追溯记录');
    check((string) Db::value('SELECT deleted_at FROM family_persons WHERE id = ?', [$duplicate]) !== '', '重复人物应软删除');
    check(db_count('audit_logs', "action = 'person.merge' AND target_id = ?", [$primary]) === 1, '合并未写审计');
});

test('跨家族 Web 访问他人家族返回 404，谱系页包含审核入口', function (): void {
    $ownerA = make_family_user('iso-web-a');
    $ownerB = make_family_user('iso-web-b');
    $familyA = \App\Services\FamilyService::create($ownerA, '甲家族', 'iso-web-a-' . bin2hex(random_bytes(3)));
    $familyB = \App\Services\FamilyService::create($ownerB, '乙家族', 'iso-web-b-' . bin2hex(random_bytes(3)));
    login((string) $ownerB['email'], 'FamilyTest@123');
    check(http('GET', '/families/' . $familyA)['status'] === 404, '跨家族访问应返回 404');
    $page = http('GET', '/families/' . $familyB . '/genealogy');
    check($page['status'] === 200, '谱系页应可访问');
    check_contains($page['body'], '提交关系候选');
    check_contains($page['body'], 'name="_token"');
});


// ===== 阶段 D：数字档案馆 =====
test('阶段 D 迁移全部执行且重复执行安全', function (): void {
    foreach (range(11, 16) as $n) {
        $version = '20261127_' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        check(
            (int) Db::value("SELECT COUNT(*) FROM schema_migrations WHERE version = ? AND status = 'applied'", [$version]) === 1,
            '阶段 D 迁移缺失：' . $version
        );
    }
    foreach (['archives', 'archive_files', 'archive_tags', 'archive_tag_links', 'archive_person_links', 'archive_versions'] as $table) {
        check(db_count($table) >= 0, '表不存在：' . $table);
    }
});

test('创建档案、编辑保留历史版本、Web 页面可见', function (): void {
    $owner = make_family_user('arch-owner');
    $familyId = \App\Services\FamilyService::create($owner, '档案家族', 'arch-family-' . bin2hex(random_bytes(3)));
    login((string) $owner['email'], 'FamilyTest@123');
    $createPath = '/families/' . $familyId . '/archives/new';
    $token = csrf_token($createPath);
    $created = http('POST', $createPath, [
        '_token' => $token,
        'title' => '祖母的嫁妆箱',
        'archive_type' => 'photo',
        'privacy' => 'private',
        'description' => '家族保存的旧照片与说明',
    ], [], false);
    check($created['status'] === 302, '创建档案应重定向');
    $archiveId = (int) Db::value('SELECT id FROM archives WHERE title = ?', ['祖母的嫁妆箱']);
    check($archiveId > 0, '档案未写入数据库');
    $show = http('GET', '/archives/' . $archiveId);
    check($show['status'] === 200, '档案详情应可访问');
    check_contains($show['body'], '祖母的嫁妆箱');
    $editToken = csrf_token('/archives/' . $archiveId);
    $edited = http('POST', '/archives/' . $archiveId . '/edit', [
        '_token' => $editToken, 'title' => '祖母的嫁妆箱（整理版）', 'privacy' => 'family', 'change_note' => '补全标题',
    ], [], false);
    check($edited['status'] === 302, '编辑档案应重定向');
    check((string) Db::value('SELECT title FROM archives WHERE id = ?', [$archiveId]) === '祖母的嫁妆箱（整理版）', '标题未更新');
    check(db_count('archive_versions', 'archive_id = ?', [$archiveId]) === 1, '修改前应保存历史版本');
});

test('档案文件上传记录 SHA-256，授权下载写审计，跨家族 404', function (): void {
    $owner = make_family_user('arch-file-owner');
    $other = make_family_user('arch-file-other');
    $familyId = \App\Services\FamilyService::create($owner, '文件家族', 'arch-file-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '老照片', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/archive-test-' . bin2hex(random_bytes(4)) . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true);
    check(is_string($png), '测试 PNG 解码失败');
    file_put_contents($tmp, $png);

    login((string) $owner['email'], 'FamilyTest@123');
    $uploadToken = csrf_token('/archives/' . $archiveId);
    $upload = http('POST', '/archives/' . $archiveId . '/files', ['_token' => $uploadToken], ['file' => $tmp], false);
    check($upload['status'] === 302, '上传应重定向');
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 1, '文件记录缺失');
    $file = Db::fetch('SELECT * FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    check((string) $file['sha256'] === hash('sha256', $png), 'SHA-256 与文件内容不符');
    $download = http('GET', '/archives/files/' . (int) $file['id'] . '/download');
    check($download['status'] === 200, '授权成员应能下载');
    check($download['body'] === $png, '下载内容不一致');
    check(db_count('audit_logs', "action = 'archive.file.download' AND target_id = ?", [(int) $file['id']]) === 1, '下载未写审计');

    login((string) $other['email'], 'FamilyTest@123');
    check(http('GET', '/archives/' . $archiveId)['status'] === 404, '跨家族查看私密档案应 404');
    check(http('GET', '/archives/files/' . (int) $file['id'] . '/download')['status'] === 404, '跨家族下载应 404');
    @unlink($tmp);
});

test('公开家族的公开档案对访客可见，但下载仍需家族成员权限', function (): void {
    $owner = make_family_user('arch-public');
    $other = make_family_user('arch-public-outsider');
    $familyId = \App\Services\FamilyService::create($owner, '公开家族', 'arch-public-' . bin2hex(random_bytes(3)));
    Db::update('families', ['privacy' => 'public'], 'id = ?', [$familyId]);
    \App\Core\Policy::clearCache();
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '公开老照片', 'privacy' => 'public']);
    $tmp = sys_get_temp_dir() . '/archive-public-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true));
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/archives/' . $archiveId);
    http('POST', '/archives/' . $archiveId . '/files', ['_token' => $token], ['file' => $tmp]);
    $fileId = (int) Db::value('SELECT id FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);

    login((string) $other['email'], 'FamilyTest@123');
    $show = http('GET', '/archives/' . $archiveId);
    check($show['status'] === 200, '公开家族的公开档案应可见');
    check_contains($show['body'], '公开老照片');
    check(http('GET', '/archives/files/' . $fileId . '/download')['status'] === 404, '非成员下载应拒绝');
    @unlink($tmp);
});

test('档案标签与人物关联、删除申请需管理员确认', function (): void {
    $owner = make_family_user('arch-tag-owner');
    $familyId = \App\Services\FamilyService::create($owner, '标签家族', 'arch-tag-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '族谱扫描件', 'privacy' => 'family']);
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '先祖某人', 'is_deceased' => 1, 'privacy' => 'family', 'birth_date' => '1930-01-01',
    ]);
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/archives/' . $archiveId);
    check(http('POST', '/archives/' . $archiveId . '/tags', ['_token' => $token, 'name' => '迁徙'], [], false)['status'] === 302, '添加标签失败');
    check(db_count('archive_tags', 'family_id = ? AND name = ?', [$familyId, '迁徙']) === 1, '标签未持久化');
    check(http('POST', '/archives/' . $archiveId . '/persons', [
        '_token' => $token, 'person_id' => $personId, 'relation_type' => 'subject',
    ], [], false)['status'] === 302, '关联人物失败');
    check(db_count('archive_person_links', 'archive_id = ? AND person_id = ?', [$archiveId, $personId]) === 1, '关联未持久化');

    check(http('POST', '/archives/' . $archiveId . '/delete-request', ['_token' => $token], [], false)['status'] === 302, '删除申请失败');
    check((string) Db::value('SELECT status FROM archives WHERE id = ?', [$archiveId]) === 'delete_requested', '未进入待审核');
    check(http('POST', '/archives/' . $archiveId . '/delete-decision', [
        '_token' => $token, 'family_id' => $familyId, 'approve' => '1',
    ], [], false)['status'] === 302, '审批失败');
    check((string) Db::value('SELECT status FROM archives WHERE id = ?', [$archiveId]) === 'deleted', '软删除未生效');
    check(http('GET', '/archives/' . $archiveId)['status'] === 404, '已删除档案不可见');
});

test('伪造 MIME 的文件被拒绝且不产生档案文件记录', function (): void {
    $owner = make_family_user('arch-mime');
    $familyId = \App\Services\FamilyService::create($owner, '校验家族', 'arch-mime-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '校验测试', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/archive-fake-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, '这其实不是 PNG 文件');
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/archives/' . $archiveId);
    http('POST', '/archives/' . $archiveId . '/files', ['_token' => $token], ['file' => $tmp]);
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 0, '非法文件不应入库');
    @unlink($tmp);
});


test('阶段 E：AI 工作流迁移与幂等创建', function (): void {
    check(db_count('schema_migrations', "version LIKE '20261127_0%' AND status = 'applied'") >= 21, '阶段 E 迁移未全部应用');
    $owner = make_family_user('ai-idem');
    $familyId = \App\Services\FamilyService::create($owner, 'AI 幂等家族', 'ai-idem-' . bin2hex(random_bytes(3)));
    $first = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '1990年3月，父亲从北方迁往南方。'], 'same-key');
    $second = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '不同内容'], 'same-key');
    check($first === $second, '同一幂等键应返回同一任务');
    check(db_count('ai_workflows', 'family_id = ?', [$familyId]) === 1, '幂等键未阻止重复任务');
    check(db_count('ai_workflow_steps', 'workflow_id = ?', [$first]) === 3, '步骤未按类型初始化');
});

test('阶段 E：AI 输出仅生成待审核候选，不自动成为事实', function (): void {
    $owner = make_family_user('ai-draft');
    $familyId = \App\Services\FamilyService::create($owner, 'AI 草稿家族', 'ai-draft-' . bin2hex(random_bytes(3)));
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/families/' . $familyId . '/ai');
    check(http('POST', '/families/' . $familyId . '/ai/workflows', [
        '_token' => $token, 'type' => 'extract_facts', 'text' => "1990年3月，父亲从北方迁往南方。母亲后来也到了南方。",
    ], [], false)['status'] === 302, '创建 AI 任务失败');
    $id = (int) Db::value('SELECT id FROM ai_workflows WHERE family_id = ? ORDER BY id DESC LIMIT 1', [$familyId]);
    $runToken = csrf_token('/families/' . $familyId . '/ai/workflows/' . $id);
    check(http('POST', '/families/' . $familyId . '/ai/workflows/' . $id . '/run', ['_token' => $runToken], [], false)['status'] === 302, '执行 AI 任务失败');
    check((string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'completed', '任务未完成');
    $output = json_decode((string) Db::value('SELECT output_json FROM ai_workflows WHERE id = ?', [$id]), true) ?: [];
    check(isset($output['content'], $output['facts']), '任务缺少草稿输出');
    check(count($output['facts']) >= 2, '未提取到候选事实');
    foreach ($output['facts'] as $fact) {
        check($fact['verification_status'] === 'pending', 'AI 候选不得自动确认');
    }
    check(db_count('ai_generation_logs', 'workflow_id = ?', [$id]) === 1, '未记录生成来源');
    check(db_count('memory_facts', 'family_id = ? AND verification_status = ?', [$familyId, 'confirmed']) === 0, 'AI 生成内容被自动确认为事实');
});

test('阶段 E：事实审核只允许家族管理员，跨家族输入被拒绝', function (): void {
    $owner = make_family_user('ai-review-owner');
    $member = make_family_user('ai-review-member');
    $other = make_family_user('ai-review-other');
    $familyId = \App\Services\FamilyService::create($owner, 'AI 审核家族', 'ai-review-' . bin2hex(random_bytes(3)));
    \App\Services\FamilyService::invite($owner, $familyId, (string) $member['email'], 'member');
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '审核人物', 'gender' => 'male', 'birth_date' => '1930-01-01', 'privacy' => 'private',
    ]);
    $factId = \App\Services\AiWorkflowService::addFactCandidate($owner, $familyId, 'event', '1990年迁居（待核实）', 'ai_extraction', null, $personId);
    try {
        \App\Services\AiWorkflowService::reviewFact($member, $factId, 'confirmed');
        check(false, '普通成员不应能确认事实');
    } catch (\App\Core\HttpException $e) {
        check(true, '普通成员越权被拒绝');
    }
    \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'confirmed');
    check((string) Db::value('SELECT verification_status FROM memory_facts WHERE id = ?', [$factId]) === 'confirmed', '审核未生效');

    $otherFamily = \App\Services\FamilyService::create($other, '他族', 'ai-other-' . bin2hex(random_bytes(3)));
    try {
        \App\Services\AiWorkflowService::create($other, $otherFamily, 'extract_facts', ['text' => '资料', 'person_id' => $personId]);
        check(false, '跨家族人物输入不应通过');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '跨家族越权应返回 404');
    }
    check(db_count('ai_workflows', 'family_id = ?', [$otherFamily]) === 0, '越权请求不应创建任务');
});

test('阶段 E：Worker 原子领取、租约恢复与取消', function (): void {
    $owner = make_family_user('ai-worker');
    $familyId = \App\Services\FamilyService::create($owner, 'AI 队列家族', 'ai-worker-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'family_history_draft', ['text' => '家族史资料']);
    $claimed = \App\Services\AiWorkflowService::claim('worker-a', 5);
    check(in_array($id, $claimed, true), '任务未被领取');
    check(\App\Services\AiWorkflowService::claim('worker-b', 5) === [], '同一任务不应被重复领取');
    // 使用数据库时钟，避免 PHP 时区与 MySQL 时区不同导致租约判定不稳定。
    Db::query('UPDATE ai_workflows SET lease_until = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id = ?', [$id]);
    $recovered = \App\Services\AiWorkflowService::recoverExpired();
    check($recovered['expired'] >= 1, '超时租约未回收');
    check((string) Db::value('SELECT failure_class FROM ai_workflows WHERE id = ?', [$id]) === 'lease_expired', '未记录租约超时');
    check(db_count('ai_workflows', 'id = ? AND status IN (\'queued\',\'failed\')', [$id]) === 1, '任务未进入可重试状态');
    \App\Services\AiWorkflowService::cancel($owner, $id);
    check((string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'cancelled', '取消任务失败');
    check(\App\Services\AiWorkflowService::claim('worker-c', 5) === [], '已取消任务不应被领取');
});

test('阶段 E：口述历史授权前不可录入，撤回后不可复核', function (): void {
    $owner = make_family_user('ai-oral');
    $familyId = \App\Services\FamilyService::create($owner, '口述家族', 'ai-oral-' . bin2hex(random_bytes(3)));
    $sessionId = \App\Services\AiWorkflowService::createOralSession($owner, $familyId, ['title' => '祖辈访谈', 'topic' => '家族迁徙']);
    try {
        \App\Services\AiWorkflowService::addSegment($owner, $sessionId, ['transcript' => '我小时候住在北方']);
        check(false, '未授权不应录入转写');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 422, '未授权录入应返回 422');
    }
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, true);
    $segmentId = \App\Services\AiWorkflowService::addSegment($owner, $sessionId, ['transcript' => '我小时候住在北方', 'speaker' => '受访者']);
    \App\Services\AiWorkflowService::reviewSegment($owner, $segmentId, true, '小时候住在北方');
    check((string) Db::value('SELECT review_status FROM oral_history_segments WHERE id = ?', [$segmentId]) === 'confirmed', '片段审核未生效');
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, false);
    try {
        \App\Services\AiWorkflowService::reviewSegment($owner, $segmentId, true);
        check(false, '撤回授权后仍可复核');
    } catch (\App\Core\HttpException $e) {
        check(true, '授权撤回后复核被拒绝');
    }
    try {
        \App\Services\AiWorkflowService::create($owner, $familyId, 'transcribe_audio', ['session_id' => $sessionId]);
        check(false, '撤回授权后仍可发起转写');
    } catch (\App\Core\HttpException $e) {
        check(true, '撤回授权后转写任务被拒绝');
    }
});


test('阶段 F：家族事件可关联人物，公开事件访客可见而私密事件不可见', function (): void {
    check(db_count('schema_migrations', "version LIKE '20261127_0%' AND status = 'applied'") >= 24, '阶段 F 迁移未全部应用');
    $owner = make_family_user('f-timeline');
    $familyId = \App\Services\FamilyService::create($owner, '时间轴家族', 'f-tl-' . bin2hex(random_bytes(3)), ['privacy' => 'public']);
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '家族长辈', 'gender' => 'female', 'birth_date' => '1930-01-01',
        'death_date' => '2000-01-01', 'privacy' => 'private',
    ]);
    $publicId = \App\Services\FamilyTimelineService::create($owner, $familyId, [
        'title' => '迁居南方', 'event_date' => '1990-03-15', 'privacy' => 'public',
        'source_type' => 'oral_history', 'person_ids' => [$personId],
    ]);
    $privateId = \App\Services\FamilyTimelineService::create($owner, $familyId, [
        'title' => '家庭内部事项', 'event_date' => '1991-04-01', 'privacy' => 'private',
    ]);
    check(count(\App\Services\FamilyTimelineService::persons($publicId)) === 1, '事件人物关联失败');
    check(count(\App\Services\FamilyTimelineService::events($owner, $familyId)) === 2, '成员应看到全部事件');
    login((string) $owner['email'], 'FamilyTest@123');
    check(http('GET', '/families/' . $familyId . '/timeline')['status'] === 200, '时间轴页面不可访问');
    reset_session();
    $visitorEvents = \App\Services\FamilyTimelineService::events(null, $familyId);
    check(count($visitorEvents) === 1 && (int) $visitorEvents[0]['id'] === $publicId, '访客可见性过滤失败');
    check(\App\Services\FamilyTimelineService::event(null, $privateId) === null, '私密事件对访客泄露');
    \App\Services\FamilyTimelineService::archive($owner, $publicId);
    check(\App\Services\FamilyTimelineService::event($owner, $publicId) === null, '归档事件仍可见');
});

test('阶段 F：展馆发布前复核内容可见性，草稿不对外暴露', function (): void {
    $owner = make_family_user('f-exhibit');
    $familyId = \App\Services\FamilyService::create($owner, '展馆家族', 'f-ex-' . bin2hex(random_bytes(3)), ['privacy' => 'public']);
    $exhibitionId = \App\Services\ExhibitionService::create($owner, $familyId, [
        'title' => '家族记忆展', 'privacy' => 'public',
    ]);
    $privateArchive = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '私密信件', 'privacy' => 'private']);
    \App\Services\ExhibitionService::addItem($owner, $exhibitionId, 'archive', $privateArchive);
    try {
        \App\Services\ExhibitionService::publish($owner, $exhibitionId);
        check(false, '含私密档案的展馆不应发布');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 422, '发布校验应返回 422');
    }
    check((string) Db::value('SELECT status FROM exhibitions WHERE id = ?', [$exhibitionId]) === 'draft', '发布失败后状态被改变');
    \App\Services\ExhibitionService::hideItem($owner, (int) Db::value('SELECT id FROM exhibition_items WHERE exhibition_id = ?', [$exhibitionId]));
    \App\Services\ExhibitionService::publish($owner, $exhibitionId);
    check((string) Db::value('SELECT status FROM exhibitions WHERE id = ?', [$exhibitionId]) === 'published', '展馆发布失败');
    check(\App\Services\ExhibitionService::visible(null, $exhibitionId) !== null, '公开展馆访客不可见');
    \App\Services\ExhibitionService::offline($owner, $exhibitionId);
    check(\App\Services\ExhibitionService::visible(null, $exhibitionId) === null, '下线展馆仍对访客可见');
});

test('阶段 F：跨家族内容不可加入展馆或归档事件', function (): void {
    $ownerA = make_family_user('f-iso-a');
    $ownerB = make_family_user('f-iso-b');
    $familyA = \App\Services\FamilyService::create($ownerA, '家族甲', 'f-iso-a-' . bin2hex(random_bytes(3)));
    $familyB = \App\Services\FamilyService::create($ownerB, '家族乙', 'f-iso-b-' . bin2hex(random_bytes(3)));
    $archiveB = \App\Services\ArchiveService::create($ownerB, $familyB, ['title' => '乙族档案', 'privacy' => 'private']);
    $exhibitionA = \App\Services\ExhibitionService::create($ownerA, $familyA, ['title' => '甲族展馆']);
    try {
        \App\Services\ExhibitionService::addItem($ownerA, $exhibitionA, 'archive', $archiveB);
        check(false, '跨家族档案不应加入展馆');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '跨家族内容应返回 404');
    }
    $eventB = \App\Services\FamilyTimelineService::create($ownerB, $familyB, ['title' => '乙族事件', 'event_date' => '1980-01-01']);
    try {
        \App\Services\FamilyTimelineService::archive($ownerA, $eventB);
        check(false, '不应归档他族事件');
    } catch (\App\Core\HttpException $e) {
        check(true, '跨家族归档被拒绝');
    }
});

test('阶段 F：通知中心、偏好与失败旁路', function (): void {
    $owner = make_family_user('f-notify');
    \App\Core\Notify::configure(['driver' => 'log']);
    $id = \App\Services\NotificationService::emit(null, (int) $owner['id'], 'family_invitation', '家族邀请', '内容');
    check((string) Db::value('SELECT status FROM notifications WHERE id = ?', [$id]) === 'sent', '站内通知未送达');
    \App\Services\NotificationService::markRead((int) $owner['id'], $id);
    check(Db::value('SELECT read_at FROM notifications WHERE id = ?', [$id]) !== null, '已读状态未保存');
    \App\Services\NotificationService::setPreference((int) $owner['id'], 'ai_workflow_completed', false, true);
    \App\Services\NotificationService::emit(null, (int) $owner['id'], 'ai_workflow_completed', '任务完成', '请审核', '/notifications', true);
    check(db_count('notifications', 'user_id = ? AND type = ? AND channel = ?', [
        (int) $owner['id'], 'ai_workflow_completed', 'email',
    ]) === 1, '邮件通知未记录');
    check((string) Db::value("SELECT status FROM notifications WHERE user_id = ? AND type = ? AND channel = 'in_app' ORDER BY id DESC LIMIT 1", [
        (int) $owner['id'], 'ai_workflow_completed',
    ]) === 'failed', '关闭站内偏好后仍送达站内通知');
    \App\Services\NotificationService::setPreference((int) $owner['id'], 'export_ready', true, true);
    \App\Core\Notify::configure(['driver' => 'none']);
    \App\Services\NotificationService::emit(null, (int) $owner['id'], 'export_ready', '导出完成', '', null, true);
    check((string) Db::value("SELECT status FROM notifications WHERE type = 'export_ready' AND channel = 'email' ORDER BY id DESC LIMIT 1") === 'failed', '邮件失败状态未记录');
    \App\Core\Notify::reset();
});
echo "\n[阶段 G：API v1 与小程序联调]\n";

test('阶段 G：API 家族/人物/谱系/档案/AI 全链路', function (): void {
    $owner = make_family_user('g-api');
    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $owner['email'], 'password' => 'FamilyTest@123',
    ]));
    check($token !== '', 'API token 签发失败');

    $created = api('POST', '/api/v1/families', [
        'name' => 'API 测试家族', 'slug' => 'g-api-' . bin2hex(random_bytes(3)),
        'privacy' => 'public', 'description' => '阶段 G 联调',
    ], $token);
    check($created['status'] === 201, 'API 创建家族失败：' . $created['raw']);
    $familyId = (int) ($created['data']['data']['id'] ?? 0);
    check($familyId > 0, 'API 未返回家族 ID');

    $list = api('GET', '/api/v1/families', null, $token);
    check($list['status'] === 200, 'API 家族列表失败：' . $list['raw']);
    check(count(array_filter($list['data']['data']['items'] ?? [], fn ($f) => (int) ($f['id'] ?? 0) === $familyId)) === 1, 'API 家族列表缺少新家族');

    $person = api('POST', '/api/v1/families/' . $familyId . '/persons', [
        'name' => 'API 祖辈', 'gender' => 'female', 'birth_date' => '1930-01-02',
        'death_date' => '2001-03-04', 'privacy' => 'private',
    ], $token);
    check($person['status'] === 201, 'API 创建人物失败：' . $person['raw']);
    $personId = (int) ($person['data']['data']['id'] ?? 0);
    $personB = api('POST', '/api/v1/families/' . $familyId . '/persons', [
        'name' => 'API 后辈', 'gender' => 'male', 'birth_date' => '1960-05-06',
        'death_date' => '2020-07-08', 'privacy' => 'private',
    ], $token);
    $personBId = (int) ($personB['data']['data']['id'] ?? 0);

    $relation = api('POST', '/api/v1/families/' . $familyId . '/relationships', [
        'person_a' => $personId, 'person_b' => $personBId, 'relationship_type' => 'parent',
    ], $token);
    check($relation['status'] === 201 && ($relation['data']['data']['status'] ?? '') === 'pending', '关系应进入待审核：' . $relation['raw']);
    $relationId = (int) ($relation['data']['data']['id'] ?? 0);
    $tree = api('GET', '/api/v1/families/' . $familyId . '/genealogy?center_id=' . $personId, null, $token);
    check($tree['status'] === 200, 'API 谱系失败：' . $tree['raw']);

    $review = api('POST', '/api/v1/families/' . $familyId . '/relationships/' . $relationId . '/review', [
        'status' => 'confirmed',
    ], $token);
    check($review['status'] === 200, 'API 关系审核失败：' . $review['raw']);

    $archive = api('POST', '/api/v1/families/' . $familyId . '/archives', [
        'title' => 'API 档案', 'archive_type' => 'document', 'privacy' => 'public',
    ], $token);
    check($archive['status'] === 201, 'API 创建档案失败：' . $archive['raw']);
    $archiveId = (int) ($archive['data']['data']['id'] ?? 0);
    $detail = api('GET', '/api/v1/archives/' . $archiveId, null, $token);
    check($detail['status'] === 200, 'API 档案详情失败：' . $detail['raw']);

    $workflow = api('POST', '/api/v1/families/' . $familyId . '/ai/workflows', [
        'type' => 'extract_facts', 'idempotency_key' => 'g-api-' . $familyId,
        'text' => '祖父于一九五六年迁居南方。',
    ], $token);
    check($workflow['status'] === 201, 'API 创建 AI 任务失败：' . $workflow['raw']);
    $workflowId = (int) ($workflow['data']['data']['id'] ?? 0);
    $repeat = api('POST', '/api/v1/families/' . $familyId . '/ai/workflows', [
        'type' => 'extract_facts', 'idempotency_key' => 'g-api-' . $familyId,
        'text' => '祖父于一九五六年迁居南方。',
    ], $token);
    check((int) ($repeat['data']['data']['id'] ?? 0) === $workflowId, 'API 幂等键未生效');
    $status = api('GET', '/api/v1/ai/workflows/' . $workflowId, null, $token);
    check($status['status'] === 200, 'API 任务状态失败：' . $status['raw']);
});

test('阶段 G：API 跨家族越权与未授权访问被拒绝', function (): void {
    $owner = make_family_user('g-iso-a');
    $other = make_family_user('g-iso-b');
    $familyId = \App\Services\FamilyService::create($owner, '隔离 A', 'g-iso-a-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '隔离人物', 'privacy' => 'private', 'death_date' => '1990-01-01',
    ]);
    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $other['email'], 'password' => 'FamilyTest@123',
    ]));

    foreach ([
        '/api/v1/families/' . $familyId,
        '/api/v1/persons/' . $personId,
        '/api/v1/families/' . $familyId . '/persons',
    ] as $path) {
        $res = api('GET', $path, null, $token);
        check($res['status'] === 404, '越权路径应返回 404：' . $path . ' got ' . $res['status']);
    }
    $anonymous = api('GET', '/api/v1/families');
    check($anonymous['status'] === 401, '未带 token 应返回 401，实际 ' . $anonymous['status']);
});

test('阶段 G：通知中心 API 已读与批量已读', function (): void {
    $user = make_family_user('g-notify');
    $id = \App\Services\NotificationService::emit(null, (int) $user['id'], 'family_invitation', 'API 通知', '内容');
    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $user['email'], 'password' => 'FamilyTest@123',
    ]));
    $list = api('GET', '/api/v1/notifications', null, $token);
    check($list['status'] === 200 && (int) ($list['data']['data']['unread_count'] ?? 0) >= 1, '通知列表失败：' . $list['raw']);
    $read = api('POST', '/api/v1/notifications/' . $id . '/read', [], $token);
    check($read['status'] === 200, '单条已读失败：' . $read['raw']);
    check(Db::value('SELECT read_at FROM notifications WHERE id = ?', [$id]) !== null, '已读状态未保存');
    $all = api('POST', '/api/v1/notifications/read-all', [], $token);
    check($all['status'] === 200, '批量已读失败：' . $all['raw']);
});

test('阶段 G：API 令牌失效与伪造身份防护', function (): void {
    $user = make_family_user('g-token');
    $res = api('POST', '/api/v1/auth/token', ['email' => (string) $user['email'], 'password' => 'FamilyTest@123']);
    $token = api_token_of($res);
    check(api('GET', '/api/v1/families', null, $token)['status'] === 200, '有效 token 应可访问');
    check(api('GET', '/api/v1/families', null, 'bad-' . $token)['status'] === 401, '无效 token 应返回 401');
    Db::query('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ?', [hash('sha256', $token)]);
    check(api('GET', '/api/v1/families', null, $token)['status'] === 401, '已撤销 token 应返回 401');

    // 客户端传入 user_id 不得改变创建者归属。
    $other = make_family_user('g-forged');
    $token2 = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $user['email'], 'password' => 'FamilyTest@123',
    ]));
    $created = api('POST', '/api/v1/families', [
        'name' => '身份防护', 'slug' => 'g-forged-' . bin2hex(random_bytes(3)),
        'user_id' => (int) $other['id'], 'owner_user_id' => (int) $other['id'],
    ], $token2);
    check($created['status'] === 201, '创建家族失败：' . $created['raw']);
    $familyId = (int) ($created['data']['data']['id'] ?? 0);
    check((int) Db::value('SELECT owner_user_id FROM families WHERE id = ?', [$familyId]) === (int) $user['id'], 'API 接受了伪造的 user_id');
});

echo "\n[补齐：外部 Provider / 标注 / 导出 / 机构预留]\n";

test('补齐：OCR/转写 Provider 可由后台配置并生效', function (): void {
    $client = 'App\\Services\\Ai\\ProcessingClient';
    check(!$client::enabled('ocr'), '未配置时 OCR 不应启用');
    \App\Core\Settings::set('ai.ocr.enabled', '1');
    \App\Core\Settings::set('ai.ocr.provider', 'custom');
    \App\Core\Settings::set('ai.ocr.base_url', 'https://ocr.example.test/v1');
    \App\Core\Settings::set('ai.ocr.api_key', 'test-key');
    \App\Core\Settings::set('ai.ocr.model', 'ocr-v1');
    check($client::enabled('ocr'), 'OCR 配置未生效');
    check($client::describe('ocr')['model'] === 'ocr-v1', 'OCR 模型未生效');
    check($client::label('asr') === '本地演示', '未配置的 ASR 应显示本地演示');
    \App\Core\Settings::set('ai.ocr.enabled', '0');
    \App\Core\Settings::set('ai.ocr.api_key', null);
    check(!$client::enabled('ocr'), '关闭后 OCR 应停用');
    \App\Core\Settings::reset();
});

test('补齐：照片区域标注需权限且审核后可确认', function (): void {
    $owner = make_family_user('tag-owner');
    $outsider = make_family_user('tag-outsider');
    $familyId = \App\Services\FamilyService::create($owner, '标注家族', 'tag-' . bin2hex(random_bytes(3)));
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, [
        'name' => '标注人物', 'privacy' => 'private', 'death_date' => '1990-01-01',
    ]);
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '标注照片', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/tag-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true));
    login((string) $owner['email'], 'FamilyTest@123');
    $token = csrf_token('/archives/' . $archiveId);
    http('POST', '/archives/' . $archiveId . '/files', ['_token' => $token], ['file' => $tmp], false);
    $fileId = (int) Db::value('SELECT id FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    check(\App\Services\ArchiveService::addPhotoTag($owner, $fileId, $personId, 10, 20, 30, 40) > 0, '标注创建失败');
    $tagId = (int) Db::value('SELECT id FROM archive_photo_tags WHERE archive_file_id = ? LIMIT 1', [$fileId]);
    check((string) Db::value('SELECT review_status FROM archive_photo_tags WHERE id = ?', [$tagId]) === 'pending', 'AI/人工标注不得自动确认');
    $show = http('GET', '/archives/' . $archiveId);
    check_contains($show['body'], 'photo-tag-form');
    \App\Services\ArchiveService::reviewPhotoTag($owner, $tagId, 'confirmed');
    check((string) Db::value('SELECT review_status FROM archive_photo_tags WHERE id = ?', [$tagId]) === 'confirmed', '审核未生效');
    try {
        \App\Services\ArchiveService::addPhotoTag($outsider, $fileId, $personId, 1, 1, 2, 2);
        check(false, '跨家族标注应被拒绝');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '越权标注应返回 404');
    }
    @unlink($tmp);
});

test('补齐：档案导出仅授权用户可用且写审计', function (): void {
    $owner = make_family_user('export-owner');
    $other = make_family_user('export-other');
    $familyId = \App\Services\FamilyService::create($owner, '导出家族', 'export-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '导出档案', 'privacy' => 'private']);
    login((string) $owner['email'], 'FamilyTest@123');
    $res = http('GET', '/archives/' . $archiveId . '/export');
    check($res['status'] === 200, '授权导出应成功：' . $res['status']);
    check(str_contains($res['body'], '导出档案'), '导出内容缺少档案标题');
    check(db_count('audit_logs', "action = 'archive.exported' AND target_id = ?", [$archiveId]) === 1, '导出未写审计');
    login((string) $other['email'], 'FamilyTest@123');
    check(http('GET', '/archives/' . $archiveId . '/export')['status'] === 404, '跨家族导出应 404');
});

test('补齐：机构租户预留仅平台管理员可管理且家族隔离', function (): void {
    $admin = \App\Core\Db::fetch("SELECT * FROM users WHERE email = 'admin@example.com' LIMIT 1");
    check(is_array($admin), '测试管理员不存在');
    $user = make_family_user('institution-user');
    $institutionId = \App\Services\InstitutionService::create($admin, '示例机构', 'inst-' . bin2hex(random_bytes(3)), ['region' => 'test']);
    $familyId = \App\Services\FamilyService::create($user, '机构家族', 'inst-family-' . bin2hex(random_bytes(3)));
    \App\Services\InstitutionService::attachFamily($admin, $institutionId, $familyId);
    check((int) \App\Services\InstitutionService::institutionOfFamily($familyId)['id'] === $institutionId, '家族未关联机构');
    check(count(\App\Services\InstitutionService::members($institutionId)) >= 1, '机构成员缺失');
    try {
        \App\Services\InstitutionService::attachFamily($user, $institutionId, $familyId);
        check(false, '普通用户不应能管理机构');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 403, '越权机构操作应 403');
    }
    check(db_count('schema_migrations', "version IN ('20261127_025','20261127_026') AND status = 'applied'") === 2, '新增迁移未执行');
});

echo "\n[Round2 Phase 1：API Token 边界与 CSRF]\n";

test('Round2 A-02：Web Session 不能替代 Bearer Token 访问 API', function (): void {
    login('demo@example.com', 'Demo@123456');
    check(http('GET', '/dashboard')['status'] === 200, 'Web 会话应能访问网页');
    check(api('GET', '/api/v1/me')['status'] === 401, 'Web Session 不能访问 API 私有读接口');
    $write = api('POST', '/api/v1/reports', ['target_type' => 'memorial', 'reason' => '测试']);
    check($write['status'] === 401, 'Web Session 不能访问 API 写接口，实际 ' . $write['status']);
    check(($write['data']['error']['code'] ?? '') === 'unauthorized', 'API 401 必须是统一 JSON');
    reset_session();
});

test('Round2 A-02：无效/撤销/过期 Token 均 401，有效 Token 正常', function (): void {
    $user = make_family_user('r2-token');
    $res = api('POST', '/api/v1/auth/token', ['email' => (string) $user['email'], 'password' => 'FamilyTest@123']);
    $token = api_token_of($res);
    check(api('GET', '/api/v1/me', null, $token)['status'] === 200, '有效 Token 应可访问');
    check(api('GET', '/api/v1/me', null, str_repeat('a', 64))['status'] === 401, '格式正确但不存在的 Token 应 401');
    check(api('GET', '/api/v1/me', null, 'short')['status'] === 401, '格式错误的 Token 应 401');
    Db::query('UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ?', [hash('sha256', $token)]);
    check(api('GET', '/api/v1/me', null, $token)['status'] === 401, '已撤销 Token 应 401');
   Db::query('UPDATE api_tokens SET revoked_at = NULL, expires_at = ? WHERE token_hash = ?', [
        date('Y-m-d H:i:s', time() - 60), hash('sha256', $token),
    ]);
    check(api('GET', '/api/v1/me', null, $token)['status'] === 401, '过期 Token 应 401');
});

test('Round2 A-02：Web 写操作仍需 CSRF Token', function (): void {
    login('demo@example.com', 'Demo@123456');
    $without = http('POST', '/families', [
        'name' => 'Round2 CSRF', 'slug' => 'r2-csrf-' . bin2hex(random_bytes(3)),
    ], [], false);
    check(in_array($without['status'], [400, 403, 419], true), '缺少 CSRF 的 Web 写请求应被拒绝，实际 ' . $without['status']);
    check(db_count('families', "slug LIKE 'r2-csrf-%'") === 0, '缺少 CSRF 的写请求不应落库');
    $with = http('POST', '/families', [
        '_token' => csrf_token('/families/new'), 'name' => 'Round2 CSRF',
        'slug' => 'r2-csrf-' . bin2hex(random_bytes(3)),
    ], [], false);
    check($with['status'] === 302, '带 CSRF 的 Web 写请求应成功，实际 ' . $with['status']);
});

test('Round2 B：100 并发最多放行 10 次且不产生重复限流行', function (): void {
    $key = \App\Core\RateLimitKeys::make('r2_concurrency', 'burst-' . bin2hex(random_bytes(6)));
    $dir = sys_get_temp_dir() . '/r2-rate-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $helper = escapeshellarg(__DIR__ . '/concurrency/rate.php');
    $cmd = 'seq 1 100 | xargs -P 25 -I{} sh -c \'php ' . $helper . ' ' . escapeshellarg($key)
        . ' > ' . escapeshellarg($dir) . '/{}\'';
    shell_exec($cmd . ' 2>/dev/null');
    $allowed = 0;
    $denied = 0;
    foreach (glob($dir . '/*') ?: [] as $file) {
        $value = trim((string) file_get_contents($file));
        if ($value === '1') {
            $allowed++;
        } elseif ($value === '0') {
            $denied++;
        }
        @unlink($file);
    }
    @rmdir($dir);
    check($allowed + $denied === 100, '并发子进程未全部完成');
    check($allowed <= 10, '100 并发最多放行 10 次，实际 ' . $allowed);
    check($allowed >= 1, '至少应放行一次');
    \App\Core\RateLimiter::clear($key);
});

echo "\n[Round2 Phase 2：AI 工作流可靠性与 Provider 安全]\n";

test('Round2 C：迁移字段、原子领取、fencing 与续租', function (): void {
    foreach (['lock_version', 'heartbeat_at', 'last_error_at'] as $column) {
        check((int) \App\Core\Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_workflows' AND column_name = ?", [$column]) === 1, '缺少并发字段：' . $column);
    }
    $owner = make_family_user('r2-fence');
    $familyId = \App\Services\FamilyService::create($owner, '并发家族', 'r2-fence-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'family_history_draft', ['text' => '并发测试资料']);

    $claimed = \App\Services\AiWorkflowService::claimStrict('worker-A', 5);
    $mine = null;
    foreach ($claimed as $row) {
        if ((int) $row['id'] === $id) { $mine = $row; break; }
    }
    check(is_array($mine), '任务未被严格领取');
    check((int) $mine['lock_version'] >= 1, '领取未递增 lock_version');
    check(\App\Services\AiWorkflowService::claimStrict('worker-B', 5) === [], '同一任务不应被第二个 Worker 领取');
    check(!\App\Services\AiWorkflowService::heartbeat($id, 'worker-B', (int) $mine['lock_version']), '非持有者不应能续租');
    check(!\App\Services\AiWorkflowService::heartbeat($id, 'worker-A', (int) $mine['lock_version'] + 1), '错误版本不应能续租');
    check(\App\Services\AiWorkflowService::heartbeat($id, 'worker-A', (int) $mine['lock_version']), '持有者应能续租');
    check(\App\Services\AiWorkflowService::owns($id, 'worker-A', (int) $mine['lock_version']), 'owns 应识别持有者');
    check(!\App\Services\AiWorkflowService::owns($id, 'worker-old', (int) $mine['lock_version']), '旧 Worker 不应拥有任务');

    // 旧 Worker 用过期版本提交不得完成任务。
    try {
        \App\Services\AiWorkflowService::process($id, 'worker-old', (int) $mine['lock_version'] - 1);
        check(false, '旧 Worker 不应能提交结果');
    } catch (Throwable $e) {
        check(true, '旧 Worker 提交被拒绝');
    }
    check((string) \App\Core\Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'processing', '旧 Worker 不应改变任务状态');
    \App\Services\AiWorkflowService::process($id, 'worker-A', (int) $mine['lock_version']);
    check((string) \App\Core\Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'completed', '合法 Worker 应完成任务');
});

test('Round2 C：租约超时条件恢复与重试计数', function (): void {
    $owner = make_family_user('r2-lease');
    $familyId = \App\Services\FamilyService::create($owner, '租约家族', 'r2-lease-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'family_history_draft', ['text' => '租约测试']);
    $claimed = \App\Services\AiWorkflowService::claimStrict('worker-lease', 5);
    check(count($claimed) >= 1, '未领取到任务');
    $before = (int) \App\Core\Db::value('SELECT lock_version FROM ai_workflows WHERE id = ?', [$id]);
    \App\Core\Db::query("UPDATE ai_workflows SET lease_until = DATE_SUB(NOW(), INTERVAL 60 SECOND) WHERE id = ?", [$id]);
    $recovered = \App\Services\AiWorkflowService::recoverExpired();
    check($recovered['expired'] >= 1, '超时租约应被回收');
    $after = (int) \App\Core\Db::value('SELECT lock_version FROM ai_workflows WHERE id = ?', [$id]);
    check($after > $before, '恢复应递增 lock_version');
    check((int) \App\Core\Db::value('SELECT retry_count FROM ai_workflows WHERE id = ?', [$id]) >= 1, '恢复应增加重试计数');
    check(\App\Core\Db::value('SELECT last_error_at FROM ai_workflows WHERE id = ?', [$id]) !== null, '恢复应记录错误时间');
    check((string) \App\Core\Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'queued', '恢复后任务应可重试');
});

test('Round2 D：Provider 地址 SSRF 防护与错误分类', function (): void {
    $client = 'App\\Services\\Ai\\ProcessingClient';
    foreach ([
        'http://provider.example.test/v1/ocr',
        'http://localhost:8080/ocr',
        'http://127.0.0.1/ocr',
        'http://169.254.169.254/latest/meta-data/',
        'https://user:pass@provider.test/ocr',
        'https://10.0.0.5/ocr',
        'https://[::1]/ocr',
        'https://example.test:8443/ocr',
    ] as $url) {
        try {
            $client::validateEndpoint($url);
            check(false, '危险地址未被拒绝：' . $url);
        } catch (\App\Services\Ai\ProcessingException $e) {
            check(in_array($e->category, ['validation_error', 'dns_error', 'network_error'], true), '拒绝类别异常：' . $e->category);
        }
    }
    $auth = new \App\Services\Ai\ProcessingException('auth_error', 'bad key');
    $rate = new \App\Services\Ai\ProcessingException('rate_limited', 'slow down', true);
    check(!$client::isRetryable($auth), 'auth_error 不应重试');
    check($client::isRetryable($rate), 'rate_limited 应可有限重试');
    check($client::isRetryable(new \App\Services\Ai\ProcessingException('server_error', 'oops', true)), '5xx 应可重试');
});

test('Round2 D：Provider 文件真实类型与大小校验', function (): void {
    \App\Core\Settings::set('ai.ocr.enabled', '1');
    \App\Core\Settings::set('ai.ocr.provider', 'custom');
    \App\Core\Settings::set('ai.ocr.base_url', 'https://provider.test/v1');
    \App\Core\Settings::set('ai.ocr.api_key', 'test-key');
    $client = 'App\\Services\\Ai\\ProcessingClient';
    $tmp = sys_get_temp_dir() . '/r2-provider-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($tmp, 'not an image');
    try {
        $client::recognize('ocr', $tmp, 'image/jpeg');
        check(false, '伪造 MIME 应被拒绝');
    } catch (\App\Services\Ai\ProcessingException $e) {
        check($e->category === 'unsupported_file', '文件类别应为 unsupported_file，实际 ' . $e->category);
    }
    try {
        $client::recognize('ocr', $tmp . '.missing', 'image/jpeg');
        check(false, '不存在文件应被拒绝');
    } catch (\App\Services\Ai\ProcessingException $e) {
        check($e->category === 'unsupported_file', '不存在文件类别错误');
    }
    @unlink($tmp);
    \App\Core\Settings::reset();
});

test('Round2 C05：独立 Worker CLI 单次模式可运行', function (): void {
    $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && timeout 25 php bin/ai-workflow-worker.php --once --limit=1 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    check($code === 0, 'Worker 单次运行失败：' . implode(' ', array_slice($output, -3)));
    check(is_file(dirname(__DIR__) . '/storage/logs/ai-workflow-worker.log'), 'Worker 日志未生成');
    check(is_file(dirname(__DIR__) . '/deploy/ai-workflow-worker.service'), 'systemd 单元缺失');
});

echo "\n[Round2 Phase 3：口述历史与 AI 资源授权]\n";

test('Round2 F：工作流详情按家族授权且输出最小化', function (): void {
    $owner = make_family_user('p3-owner');
    $outsider = make_family_user('p3-outsider');
    $familyId = \App\Services\FamilyService::create($owner, 'AI 授权家族', 'p3-ai-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '内部资料']);
    check(\App\Services\AiWorkflowService::getVisible($owner, $id) !== null, '创建者应可见');
    check(\App\Services\AiWorkflowService::getVisible($outsider, $id) === null, '跨家族任务应不可见');
    check(\App\Services\AiWorkflowService::visibleSteps($outsider, $id) === [], '跨家族步骤应不可见');
    login((string) $owner['email'], 'FamilyTest@123');
    $res = api('GET', '/api/v1/ai/workflows/' . $id, null, api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $owner['email'], 'password' => 'FamilyTest@123',
    ])));
    check($res['status'] === 200, '工作流详情应可访问：' . $res['raw']);
    check(!str_contains($res['raw'], 'input_json') && !str_contains($res['raw'], '内部资料'), '详情不应暴露输入或内部字段');
});

test('Round2 E：会话授权、跨家族与撤销授权停止转写', function (): void {
    $owner = make_family_user('p3-oral');
    $outsider = make_family_user('p3-oral-out');
    $familyId = \App\Services\FamilyService::create($owner, '口述家族', 'p3-oral-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $sessionId = \App\Services\AiWorkflowService::createOralSession($owner, $familyId, ['title' => '祖辈访谈']);
    try {
        \App\Services\AiWorkflowService::sessionAudio($outsider, $sessionId);
        check(false, '未授权用户不应读取会话音频');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '跨家族会话应 404');
    }
    try {
        \App\Services\AiWorkflowService::transcribeSession($owner, $sessionId);
        check(false, '未授权会话不应创建转写任务');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 422, '未授权会话应 422');
    }
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, true);
    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $owner['email'], 'password' => 'FamilyTest@123',
    ]));
    $sessions = api('GET', '/api/v1/families/' . $familyId . '/ai/sessions', null, $token);
    check($sessions['status'] === 200, '会话列表应可访问');
    $one = api('GET', '/api/v1/ai/sessions/' . $sessionId, null, $token);
    check($one['status'] === 200, '会话详情应可访问');
    $revoke = api('POST', '/api/v1/ai/sessions/' . $sessionId . '/consent', ['granted' => false], $token);
    check($revoke['status'] === 200, '撤销授权应成功');
    check((string) Db::value('SELECT consent_status FROM oral_history_sessions WHERE id = ?', [$sessionId]) === 'revoked', '授权状态未更新');
});

test('Round2 F02：事实候选家族校验与审核状态流转', function (): void {
    $owner = make_family_user('p3-fact');
    $other = make_family_user('p3-fact-out');
    $familyId = \App\Services\FamilyService::create($owner, '事实家族', 'p3-fact-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $otherFamily = \App\Services\FamilyService::create($other, '他族', 'p3-fact-o-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '人物', 'privacy' => 'private', 'death_date' => '1990-01-01']);
    $factId = \App\Services\AiWorkflowService::addFactCandidate($owner, $familyId, 'event', '待核实事件', 'user_input', null, $personId);
    try {
        \App\Services\AiWorkflowService::addFactCandidate($other, $otherFamily, 'event', '跨族事实', 'user_input', null, $personId);
        check(false, '跨家族人物事实应被拒绝');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '跨家族人物应 404');
    }
    \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'confirmed');
    check((string) Db::value('SELECT verification_status FROM memory_facts WHERE id = ?', [$factId]) === 'confirmed', '审核未生效');
    try {
        \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'rejected');
        check(false, '重复状态变更应被拒绝');
    } catch (\App\Core\HttpException $e) {
        check(in_array($e->getStatusCode(), [409, 422], true), '并发/重复审核应拒绝');
    }
    check(db_count('audit_logs', "action = 'ai.fact.reviewed' AND target_id = ?", [$factId]) >= 1, '审核未写审计');
});

test('Round2 E04：分段修正、审核与导出仅含已确认内容', function (): void {
    $owner = make_family_user('p3-seg');
    $familyId = \App\Services\FamilyService::create($owner, '分段家族', 'p3-seg-' . bin2hex(random_bytes(3)), ['privacy' => 'private']);
    $sessionId = \App\Services\AiWorkflowService::createOralSession($owner, $familyId, ['title' => '访谈']);
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, true);
    $segId = \App\Services\AiWorkflowService::addSegmentChecked($owner, $sessionId, [
        'speaker' => '受访者', 'start_time' => '00:00:01', 'end_time' => '00:00:09', 'transcript' => '原始转写',
    ]);
    check($segId > 0, '分段创建失败');
    $token = api_token_of(api('POST', '/api/v1/auth/token', ['email' => (string) $owner['email'], 'password' => 'FamilyTest@123']));
    $list = api('GET', '/api/v1/ai/sessions/' . $sessionId . '/segments', null, $token);
    check($list['status'] === 200, '分段列表应可访问');
    $correct = api('POST', '/api/v1/ai/segments/' . $segId . '/correct', ['corrected_transcript' => '人工修正稿'], $token);
    check($correct['status'] === 200, '人工修正应成功：' . $correct['raw']);
    check((string) Db::value('SELECT review_status FROM oral_history_segments WHERE id = ?', [$segId]) === 'pending', '修正后应重新待审核');
    $review = api('POST', '/api/v1/ai/segments/' . $segId . '/review', ['approve' => true, 'corrected_transcript' => '人工修正稿'], $token);
    check($review['status'] === 200, '审核应成功：' . $review['raw']);
    $export = api('GET', '/api/v1/ai/sessions/' . $sessionId . '/export', null, $token);
    check($export['status'] === 200 && str_contains($export['raw'], '人工修正稿'), '导出应包含已确认的修正稿');
});

echo "\n[Round2 Phase 4：API 标准化 / 标注 / 机构边界]\n";

test('Round2 G03：统一分页结构', function (): void {
    $res = \App\Api\Api::paged([['id' => 1], ['id' => 2]], 2, 10, 25);
    $data = json_decode($res->body(), true);
    check(is_array($data), '分页响应应为 JSON');
    check(count($data['data']['items'] ?? []) === 2, 'items 缺失');
    check(($data['data']['pagination']['page'] ?? 0) === 2, 'page 错误');
    check(($data['data']['pagination']['per_page'] ?? 0) === 10, 'per_page 错误');
    check(($data['data']['pagination']['total'] ?? 0) === 25, 'total 错误');
    check(($data['data']['pagination']['total_pages'] ?? 0) === 3, 'total_pages 错误');
});

test('Round2 H03：照片标注人物必须属于同一家族', function (): void {
    $ownerA = make_family_user('p4-tag-a');
    $ownerB = make_family_user('p4-tag-b');
    $familyA = \App\Services\FamilyService::create($ownerA, '标注家族A', 'p4-tag-a-' . bin2hex(random_bytes(3)));
    $familyB = \App\Services\FamilyService::create($ownerB, '标注家族B', 'p4-tag-b-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($ownerA, $familyA, ['title' => '标注档案', 'privacy' => 'private']);
    $personB = \App\Services\FamilyPersonService::create($ownerB, $familyB, [
        'name' => '他族人物', 'privacy' => 'private', 'death_date' => '1990-01-01',
    ]);
    $tmp = sys_get_temp_dir() . '/p4-tag-' . bin2hex(random_bytes(4)) . '.png';
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true));
    login((string) $ownerA['email'], 'FamilyTest@123');
    http('POST', '/archives/' . $archiveId . '/files', ['_token' => csrf_token('/archives/' . $archiveId)], ['file' => $tmp], false);
    $fileId = (int) Db::value('SELECT id FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    try {
        \App\Services\ArchiveService::addPhotoTag($ownerA, $fileId, $personB, 10, 10, 20, 20);
        check(false, '跨家族人物不得标注到本家族档案');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '跨家族标注应 404');
    }
    check(db_count('archive_photo_tags', 'archive_file_id = ?', [$fileId]) === 0, '不应写入跨家族标注');
    @unlink($tmp);
});

test('Round2 I：机构事务一致性、最后 owner 与重复归属', function (): void {
    $admin = Db::fetch("SELECT * FROM users WHERE email = 'admin@example.com' LIMIT 1");
    check(is_array($admin), '测试管理员不存在');
    $instId = \App\Services\InstitutionService::create($admin, '边界机构', 'p4-inst-' . bin2hex(random_bytes(3)));
    check(db_count('institutions', 'id = ? AND status = ?', [$instId, 'active']) === 1, '机构创建失败');
    check(count(array_filter(\App\Services\InstitutionService::members($instId), fn ($m) => $m['role'] === 'owner')) === 1, 'owner 成员缺失');
    try {
        \App\Services\InstitutionService::setRole($admin, $instId, (int) $admin['id'], 'member');
        check(false, '最后一个 owner 不应被降级');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 422, '降级最后 owner 应 422');
    }
    $user = make_family_user('p4-inst-family');
    $familyId = \App\Services\FamilyService::create($user, '机构家族', 'p4-inst-family-' . bin2hex(random_bytes(3)));
    \App\Services\InstitutionService::attachFamily($admin, $instId, $familyId);
    check((int) \App\Services\InstitutionService::institutionOfFamily($familyId)['id'] === $instId, '家族归属未建立');
    try {
        \App\Services\InstitutionService::attachFamily($admin, $instId, $familyId);
        check(false, '重复归属应拒绝');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 409, '重复归属应 409');
    }
});

echo "\n[Round2 Phase 5：事务一致性]\n";

test('Round2 J：档案版本事务与删除状态机', function (): void {
    $owner = make_family_user('p5-archive');
    $familyId = \App\Services\FamilyService::create($owner, '一致性家族', 'p5-' . bin2hex(random_bytes(3)));
    $id = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '原始标题', 'privacy' => 'private']);
    \App\Services\ArchiveService::update($owner, $id, ['title' => '第一次修改', 'change_note' => 'v1']);
    \App\Services\ArchiveService::update($owner, $id, ['title' => '第二次修改', 'change_note' => 'v2']);
    check(db_count('archive_versions', 'archive_id = ?', [$id]) === 2, '版本快照数量应为 2');
    check((int) Db::value('SELECT MAX(version) FROM archive_versions WHERE archive_id = ?', [$id]) === 2, '版本号应连续');
    \App\Services\ArchiveService::requestDelete($owner, $id);
    try {
        \App\Services\ArchiveService::requestDelete($owner, $id);
        check(false, '重复删除申请应被拒绝');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 409, '重复申请应 409');
    }
    \App\Services\ArchiveService::approveDelete($owner, $id, true);
    check((string) Db::value('SELECT status FROM archives WHERE id = ?', [$id]) === 'deleted', '删除审批应生效');
    try {
        \App\Services\ArchiveService::approveDelete($owner, $id, true);
        check(false, '重复审批应被拒绝');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 404, '已处理申请应 404');
    }
});

test('页脚显示版本号印记', function (): void {
    $home = http('GET', '/');
    check_contains($home['body'], 'site-footer__version');
    check_contains($home['body'], 'v2.1.0');
});

test('代码审查：Db 拒绝非法列名', function (): void {
    $rejected = false;
    try {
        \App\Core\Db::insert('users', ['bad-name' => 'x']);
    } catch (\InvalidArgumentException $e) {
        $rejected = true;
    }
    check($rejected, 'Db::insert 必须校验列名');
    $rejected = false;
    try {
        \App\Core\Db::update('users', ['1bad' => 'x'], 'id = ?', [0]);
    } catch (\InvalidArgumentException $e) {
        $rejected = true;
    }
    check($rejected, 'Db::update 必须校验列名');
});

echo "\n[Phase B 安全回归]\n";

test('B-R01：非 local/testing 环境使用占位 APP_KEY 时配置加载失败', function (): void {
    $php = escapeshellarg(PHP_BINARY);
    $cmd = 'cd ' . escapeshellarg(BASE_PATH) . ' && APP_ENV=production APP_KEY= ' . $php . ' -r '
        . escapeshellarg('define("BASE_PATH", getcwd()); require "config/config.php"; echo "loaded";') . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    check($rc !== 0, '生产环境使用空 APP_KEY 应 fail-closed');
    check(str_contains(implode("\n", $out), 'APP_KEY'), '错误信息应指出 APP_KEY');
});

test('B-B1/B5：签名媒体绑定可见性，私有响应 no-store', function (): void {
    $detail = api('GET', '/api/v1/memorials/zhang-mingyuan');
    $mediaList = $detail['data']['data']['media'] ?? [];
    check(is_array($mediaList) && $mediaList !== [], '前置：详情应含媒体');
    $item = $mediaList[0];
    $url = (string) ($item['media_url'] ?? '');
    check(str_contains($url, 'sig='), '应返回签名 URL');

    reset_session();
    $ok = http('GET', $url, null, [], false);
    check($ok['status'] === 200, '有效签名应返回 200');
    check(str_contains($ok['headers'], 'no-store'), '私有媒体响应应 no-store');

    $mediaId = (int) $item['id'];
    $originalVisibility = (string) ($item['visibility'] ?? 'visitors');
    Db::update('media', ['visibility' => 'members'], 'id = ?', [$mediaId]);
    try {
        $denied = http('GET', $url, null, [], false);
        check($denied['status'] === 404, '可见性下调后旧签名应失效（404）');
    } finally {
        Db::update('media', ['visibility' => $originalVisibility], 'id = ?', [$mediaId]);
    }
});

test('B-R02：纪念馆邀请仅限受邀邮箱本人，空邮箱邀请被拒绝', function (): void {
    $ownerToken = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => 'demo@example.com', 'password' => 'Demo@123456',
    ]));
    check($ownerToken !== '', '前置：馆主应可登录');
    $memorialId = (int) Db::value('SELECT id FROM memorials WHERE slug = ?', ['zhang-mingyuan']);
    $invitee = make_family_user('b-invitee');
    $other = make_family_user('b-other');

    $invite = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', [
        'email' => $invitee['email'], 'role' => 'viewer',
    ], $ownerToken);
    check($invite['status'] === 201, '邀请应创建成功');
    $inviteUrl = (string) ($invite['data']['data']['invite_url'] ?? '');
    $token = basename((string) (parse_url($inviteUrl, PHP_URL_PATH) ?: ''));
    check($token !== '', '应返回邀请链接');

    login((string) $other['email'], 'FamilyTest@123');
    $csrf = csrf_token('/invitations/' . $token);
    $denied = http('POST', '/invitations/' . $token, ['_token' => $csrf]);
    check($denied['status'] === 404, '非受邀用户接受应 404');

    login((string) $invitee['email'], 'FamilyTest@123');
    $csrf = csrf_token('/invitations/' . $token);
    $accepted = http('POST', '/invitations/' . $token, ['_token' => $csrf]);
    check($accepted['status'] === 200, '受邀邮箱本人应接受成功');

    $empty = api('POST', '/api/v1/manage/memorials/' . $memorialId . '/members/invite', [
        'email' => '', 'role' => 'viewer',
    ], $ownerToken);
    check($empty['status'] === 422, '空邮箱邀请应 422');
});

test('B-R12：修改密码后旧 API Token 立即失效', function (): void {
    $user = make_family_user('b-pwd');
    $email = (string) $user['email'];
    $oldToken = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => $email, 'password' => 'FamilyTest@123',
    ]));
    check($oldToken !== '', '前置：应能换取 token');

    login($email, 'FamilyTest@123');
    $csrf = csrf_token('/account?tab=security');
    $changed = http('POST', '/account/password', [
        '_token' => $csrf,
        'current_password' => 'FamilyTest@123',
        'password' => 'Changed@123456',
        'password_confirmation' => 'Changed@123456',
    ]);
    check($changed['status'] === 200, '修改密码应成功');

    check(api('GET', '/api/v1/me', null, $oldToken)['status'] === 401, '旧 token 应失效');
    $newToken = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => $email, 'password' => 'Changed@123456',
    ]));
    check($newToken !== '', '新密码应可换取 token');
    check(api('GET', '/api/v1/me', null, $newToken)['status'] === 200, '新 token 应可用');
});

test('B-R03：撤回授权后 AI 任务不再执行，失败任务不复活', function (): void {
    $owner = make_family_user('b-consent');
    $familyId = \App\Services\FamilyService::create($owner, '同意复核家族', 'b-consent-' . bin2hex(random_bytes(3)));
    $sessionId = \App\Services\AiWorkflowService::createOralSession($owner, $familyId, [
        'title' => '授权复核', 'topic' => '测试',
    ]);
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, true);
    $workflowId = \App\Services\AiWorkflowService::create(
        $owner, $familyId, 'extract_facts',
        ['session_id' => $sessionId, 'text' => '家族记忆资料'],
        'b-consent-' . $sessionId
    );
    \App\Services\AiWorkflowService::setConsent($owner, $sessionId, false);

    try {
        \App\Services\AiWorkflowService::process($workflowId, 'b-test-worker');
        check(false, '撤回授权后不应继续执行');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 422, '应返回 422');
    }
    $row = Db::fetch('SELECT status, failure_class, retry_count, max_retries FROM ai_workflows WHERE id = ?', [$workflowId]);
    check($row !== null, '工作流应存在');
    check((string) $row['failure_class'] === 'input_error', '应归类为 input_error');
    check((int) $row['retry_count'] >= (int) $row['max_retries'], '不应继续重试');

    Db::update('ai_workflows', ['status' => 'failed', 'retry_count' => 0], 'id = ?', [$workflowId]);
    \App\Services\AiWorkflowService::revokeSessionConsent($owner, $sessionId);
    check(
        (string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$workflowId]) === 'cancelled',
        'failed 工作流也应在撤回授权时取消'
    );
});

echo "\n[Phase C：数据库结构与请求追踪]\n";

test('C1：迁移列与元数据表结构完整', function (): void {
    $expectedColumns = [
        ['ai_workflows', 'lock_version'],
        ['ai_workflows', 'heartbeat_at'],
        ['ai_workflows', 'last_error_at'],
        ['memory_facts', 'source_archive_id'],
        ['memory_facts', 'source_excerpt'],
        ['memory_facts', 'subject_type'],
        ['audit_logs', 'request_id'],
    ];
    foreach ($expectedColumns as [$table, $column]) {
        $found = (int) Db::value(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        check($found === 1, '缺少列 ' . $table . '.' . $column);
    }
    check(db_count('archive_cleanup_queue') >= 0, '归档清理队列表不可查询');
    $versions = Db::fetchAll("SELECT version FROM schema_migrations WHERE version LIKE '20261128_%'");
    $found = array_column($versions, 'version');
    foreach (['20261128_001', '20261128_002', '20261128_003', '20261128_004'] as $version) {
        check(in_array($version, $found, true), '缺少迁移版本 ' . $version);
    }
    check(db_count('schema_migrations', "status <> 'applied'") === 0, '存在未应用的迁移');
});

test('C4：Web/API 响应携带 request_id，审计记录可关联', function (): void {
    $page = http('GET', '/', null, [], false);
    check($page['status'] === 200, '首页应可访问');
    check(preg_match('/X-Request-Id:\s*([A-Za-z0-9_-]{1,32})/i', $page['headers'], $m) === 1, '缺少 X-Request-Id 响应头');
    check(strlen($m[1]) === 32 || ctype_alnum($m[1]), '请求 ID 格式不合法');

    $unauth = api('GET', '/api/v1/me');
    check($unauth['status'] === 401, '未登录访问 /me 应 401');
    check(isset($unauth['data']['request_id']), 'API 错误缺少 request_id');
    check(is_string($unauth['data']['request_id']), 'request_id 应为字符串');

    $owner = make_family_user('c-request-audit');
    \App\Services\FamilyService::create($owner, '请求追踪家族', 'c-req-' . bin2hex(random_bytes(3)));
    check(
        db_count('audit_logs', "action = 'family.create' AND request_id IS NOT NULL AND request_id <> ''") >= 1,
        '审计日志未写入 request_id'
    );
});

echo "\n[Phase C：AI 可靠性与事实来源]\n";

test('C2：取消工作流后旧 fencing token 无法写回结果', function (): void {
    $owner = make_family_user('c-fence');
    $familyId = \App\Services\FamilyService::create($owner, '围栏家族', 'c-fence-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1990年3月，父亲从北方迁往南方。',
    ], 'c-fence-key');
    $claimed = \App\Services\AiWorkflowService::claimStrict('c-worker-a', 50);
    $token = null;
    foreach ($claimed as $row) {
        if ((int) $row['id'] === $id) {
            $token = (int) $row['lock_version'];
            break;
        }
    }
    check($token !== null, '测试任务未被领取');
    \App\Services\AiWorkflowService::cancel($owner, $id);
    try {
        \App\Services\AiWorkflowService::process($id, 'c-worker-a', $token);
        check(false, '旧 Worker 不应能写回已取消任务');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), '持有权') || str_contains($e->getMessage(), '不可执行'), '拒绝原因不符');
    }
    check((string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'cancelled', '任务状态应保持 cancelled');
});

test('C2：AI 事实候选记录完整来源与主体字段', function (): void {
    $owner = make_family_user('c-fact');
    $familyId = \App\Services\FamilyService::create($owner, '事实来源家族', 'c-fact-' . bin2hex(random_bytes(3)));
    $personId = \App\Services\FamilyPersonService::create($owner, $familyId, ['name' => '来源人物', 'is_deceased' => true]);
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, [
        'title' => '事实来源档案', 'privacy' => 'private',
    ]);
    $factId = \App\Services\AiWorkflowService::addFactCandidate(
        $owner, $familyId, 'date', '1990年3月迁往南方', 'ai_extraction',
        $archiveId, $personId, 0.75, '原文：1990年3月迁往南方', $archiveId, null, 'person', $personId
    );
    $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
    check($fact !== null, '事实未入库');
    check((string) $fact['source_excerpt'] === '原文：1990年3月迁往南方', '来源摘录未写入');
    check((int) $fact['source_archive_id'] === $archiveId, '来源档案未写入');
    check((string) $fact['subject_type'] === 'person' && (int) $fact['subject_id'] === $personId, '主体字段未写入');
    check((string) $fact['verification_status'] === 'pending', 'AI 事实不得自动确认');
});

echo "\n[Phase C：归档异步清理]\n";

test('C4：归档审批入队，worker 幂等清理且不动共享文件', function (): void {
    $owner = make_family_user('c-cleanup');
    $familyId = \App\Services\FamilyService::create($owner, '清理家族', 'c-cleanup-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '待清理档案', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/c-cleanup-' . bin2hex(random_bytes(4)) . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', true);
    check(is_string($png), '测试 PNG 解码失败');
    file_put_contents($tmp, $png);

    login((string) $owner['email'], 'FamilyTest@123');
    $upload = http('POST', '/archives/' . $archiveId . '/files', [
        '_token' => csrf_token('/archives/' . $archiveId),
    ], ['file' => $tmp], false);
    check($upload['status'] === 302, '上传应重定向');
    $file = Db::fetch('SELECT * FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    check($file !== null, '缺少档案文件记录');
    $storageKey = (string) $file['storage_key'];
    $absolute = \App\Core\Storage::absolute($storageKey);
    check($absolute !== null && is_file($absolute), '物理文件应存在');

    \App\Services\ArchiveService::requestDelete($owner, $archiveId);
    \App\Services\ArchiveService::approveDelete($owner, $archiveId, true);
    check((string) Db::value('SELECT status FROM archives WHERE id = ?', [$archiveId]) === 'deleted', '档案应进入 deleted');
    check(db_count('archive_cleanup_queue', "archive_id = ? AND status = 'pending'", [$archiveId]) === 1, '应生成一条待清理队列');
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 1, '审批时不应同步删除文件记录');
    check(is_file((string) $absolute), '审批时不应同步删除物理文件');

    $command = 'php ' . escapeshellarg(BASE_PATH . '/bin/archive-cleanup.php') . ' --once --limit=10 2>&1';
    $output = [];
    $rc = 0;
    exec($command, $output, $rc);
    check($rc === 0, '清理 worker 失败：' . implode("\n", $output));
    check(db_count('archive_cleanup_queue', "archive_id = ? AND status = 'done'", [$archiveId]) === 1, '队列项应完成');
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 0, '文件记录应被清理');
    check(!is_file($absolute), '物理文件应被清理');

    $output2 = [];
    $rc2 = 0;
    exec($command, $output2, $rc2);
    check($rc2 === 0, '重复运行 worker 不应失败');
    check(db_count('archive_cleanup_queue', "archive_id = ? AND status = 'done'", [$archiveId]) === 1, '重复运行不应新增队列项');
    @unlink($tmp);
});

echo "\n[Phase D：配置与审计]\n";

test('D：审计元信息脱敏（邮箱/密码/证件号）', function (): void {
    $email = 'd-audit-' . getmypid() . '@example.com';
    \App\Core\Audit::log('d.meta.redaction', 'user', 1, [
        'email' => $email,
        'password' => 'PlainSecret' . getmypid(),
        'token' => 'secret-token-value',
        'id_number' => '110101199001011234',
        'note' => "第一行\n第二行",
    ], 1);
    $row = Db::fetch("SELECT meta FROM audit_logs WHERE action = 'd.meta.redaction' ORDER BY id DESC LIMIT 1");
    check($row !== null, '审计记录未写入');
    $raw = (string) $row['meta'];
    $meta = json_decode($raw, true);
    check(is_array($meta), '审计 meta 不是合法 JSON');
    check(str_contains((string) ($meta['email'] ?? ''), '***@example.com'), '邮箱未脱敏');
    check(!str_contains($raw, 'PlainSecret'), '密码原文不应写入审计');
    check(!str_contains($raw, 'secret-token-value'), 'Token 原文不应写入审计');
    check(!str_contains($raw, '110101199001011234'), '证件号不应写入审计');
    check(($meta['password'] ?? '') === '[redacted]', '敏感键应标记为已脱敏');
});

test('D：API 令牌签发写入带 actor 的审计', function (): void {
    $login = api('POST', '/api/v1/auth/token', ['email' => 'demo@example.com', 'password' => 'Demo@123456']);
    check($login['status'] === 200, 'API 登录应成功');
    check(
        db_count('audit_logs', "action = 'auth.api_token_create' AND actor_user_id IS NOT NULL") >= 1,
        'API 令牌签发审计缺失 actor_user_id'
    );
});

test('D：生产环境开启 APP_DEBUG 时配置 fail-closed', function (): void {
    $script = sys_get_temp_dir() . '/yxt-debug-check-' . getmypid() . '.php';
    file_put_contents($script, '<?php define("BASE_PATH", ' . var_export(BASE_PATH, true) . '); require BASE_PATH . "/app/bootstrap.php";');
    $command = 'APP_ENV=production APP_DEBUG=true php ' . escapeshellarg($script) . ' 2>&1';
    $output = [];
    $rc = 0;
    exec($command, $output, $rc);
    @unlink($script);
    check($rc !== 0, '生产环境 APP_DEBUG=true 必须拒绝启动');
    check(str_contains(implode("\n", $output), 'APP_DEBUG'), '错误信息应说明 APP_DEBUG');
});

echo "\n[Phase D：小程序页面]\n";

test('D：小程序新增页面已注册且四件套齐全', function (): void {
    $config = json_decode((string) file_get_contents(BASE_PATH . '/miniprogram/app.json'), true);
    $pages = (array) ($config['pages'] ?? []);
    $required = [
        'pages/persons/list',
        'pages/genealogy/index',
        'pages/timeline/list',
        'pages/exhibitions/list',
        'pages/ai/workflow',
        'pages/invitations/accept',
    ];
    foreach ($required as $page) {
        check(in_array($page, $pages, true), '小程序未注册页面 ' . $page);
        foreach (['js', 'json', 'wxml', 'wxss'] as $ext) {
            check(is_file(BASE_PATH . '/miniprogram/' . $page . '.' . $ext), '缺少页面文件 ' . $page . '.' . $ext);
        }
    }
});

echo "\n[Phase D：性能索引]\n";

test('D：性能索引迁移已应用', function (): void {
    check(
        db_count('schema_migrations', "version = '20261128_005' AND status = 'applied'") === 1,
        '性能索引迁移 20261128_005 未应用'
    );
    $personIndex = Db::fetchAll("SHOW INDEX FROM family_persons WHERE Key_name = 'idx_person_family_deleted_id'");
    check(count($personIndex) >= 1, 'family_persons 组合索引缺失');
    $relationIndex = Db::fetchAll("SHOW INDEX FROM person_relationships WHERE Key_name = 'idx_rel_family_id'");
    check(count($relationIndex) >= 1, 'person_relationships 组合索引缺失');
});

echo "\n[Phase E：最终回归补充]\n";

test('E：AI 工作流完成写入站内通知且只写一次', function (): void {
    $owner = make_family_user('e-notify');
    $familyId = \App\Services\FamilyService::create($owner, '通知家族', 'e-notify-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1990年3月，祖父从北方迁往南方。',
    ], 'e-notify-key');
    \App\Services\AiWorkflowService::process($id, 'e-inline');
    check((string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) === 'completed', '任务未完成');
    check(
        db_count('notifications', "user_id = ? AND type = 'ai_workflow_completed' AND link LIKE ?", [
            (int) $owner['id'], '%workflow=' . $id,
        ]) === 1,
        '应生成且仅生成一条完成通知'
    );
});

test('E：10 个 Worker 并发领取同一任务只有一个有效执行者', function (): void {
    $owner = make_family_user('e-claim-race');
    $familyId = \App\Services\FamilyService::create($owner, '并发家族', 'e-race-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '并发领取验证资料。',
    ], 'e-race-' . bin2hex(random_bytes(6)));

    $script = BASE_PATH . '/tests/concurrency/claim-once.php';
    $processes = [];
    for ($i = 0; $i < 10; $i++) {
        $command = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg('e-worker-' . $i) . ' 1';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $handle = proc_open($command, $descriptors, $pipes);
        check(is_resource($handle), '无法启动并发领取子进程');
        $processes[] = [$handle, $pipes];
    }

    $winners = [];
    foreach ($processes as [$handle, $pipes]) {
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($handle);
        check($rc === 0, '并发领取子进程失败：' . $err);
        $ids = json_decode($out, true);
        check(is_array($ids), '并发领取输出不是 JSON：' . $out);
        if (in_array($id, array_map('intval', $ids), true)) {
            $winners[] = $id;
        }
    }
    check(count($winners) === 1, '同一任务只能被一个 Worker 领取，实际：' . count($winners));
    $workflow = Db::fetch('SELECT status, locked_by FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
    check($workflow !== null && $workflow['status'] === 'processing', '任务应处于 processing');
    check(str_starts_with((string) $workflow['locked_by'], 'e-worker-'), 'locked_by 应为并发 Worker 之一');
});

echo "\n[v2.1.1 Phase 1：AI 工作流真实步骤状态机]\n";

test('P1：步骤由真实执行驱动，成功时审核步骤进入待审核且写入状态历史', function (): void {
    $owner = make_family_user('p1-step');
    $familyId = \App\Services\FamilyService::create($owner, '步骤状态家族', 'p1-step-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1990年3月，父亲从北方迁往南方。',
    ], 'p1-step-' . bin2hex(random_bytes(4)));
    $result = \App\Services\AiWorkflowService::process($id, 'p1-step-worker');
    check((string) $result['status'] === 'completed', '工作流应完成');
    $steps = \App\Services\AiWorkflowService::steps($id);
    check(count($steps) === 3, '应有 3 个步骤');
    $byName = [];
    foreach ($steps as $step) {
        $byName[(string) $step['name']] = (string) $step['status'];
    }
    check(($byName['validate_input'] ?? '') === 'completed', '输入校验步骤应完成');
    check(($byName['extract_candidates'] ?? '') === 'completed', '提取步骤应完成');
    check(($byName['review_draft'] ?? '') === 'awaiting_review', '审核步骤应待人工审核，不能自动完成');
    check(db_count('ai_workflow_step_transitions', 'workflow_id = ?', [$id]) >= 3, '应记录步骤转换历史');
    check(
        db_count('ai_workflow_step_transitions', "workflow_id = ? AND new_status = 'awaiting_review'", [$id]) === 1,
        '应记录一次进入待审核的转换'
    );
});

test('P1：人工审核步骤只能从待审核推进，重复审核被拒绝', function (): void {
    $owner = make_family_user('p1-review');
    $familyId = \App\Services\FamilyService::create($owner, '步骤审核家族', 'p1-review-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'biography_draft', [
        'text' => '祖父是一位木匠，1920年生于南方。',
    ], 'p1-review-' . bin2hex(random_bytes(4)));
    \App\Services\AiWorkflowService::process($id, 'p1-review-worker');
    $reviewStep = null;
    foreach (\App\Services\AiWorkflowService::steps($id) as $step) {
        if ((string) $step['name'] === 'review_draft') {
            $reviewStep = $step;
        }
    }
    check($reviewStep !== null, '缺少审核步骤');
    \App\Services\AiWorkflowService::reviewStep($owner, (int) $reviewStep['id'], 'approved');
    check(
        (string) Db::value('SELECT status FROM ai_workflow_steps WHERE id = ?', [(int) $reviewStep['id']]) === 'approved',
        '审核通过后状态应为 approved'
    );
    try {
        \App\Services\AiWorkflowService::reviewStep($owner, (int) $reviewStep['id'], 'rejected');
        check(false, '已完成审核不应再次变更');
    } catch (\App\Core\HttpException $e) {
        check($e->getStatusCode() === 409, '重复审核应返回 409');
    }
});

test('P1：未执行步骤不能被伪造成已完成', function (): void {
    $owner = make_family_user('p1-fake');
    $familyId = \App\Services\FamilyService::create($owner, '防伪造家族', 'p1-fake-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1900年，家族迁居北方。',
    ], 'p1-fake-' . bin2hex(random_bytes(4)));
    $first = \App\Services\AiWorkflowService::steps($id)[0] ?? null;
    check($first !== null, '缺少步骤');
    // 人为把首步置为 completed，模拟“未执行却已完成”的脏状态。
    Db::update('ai_workflow_steps', ['status' => 'completed'], 'id = ?', [(int) $first['id']]);
    try {
        \App\Services\AiWorkflowService::process($id, 'p1-fake-worker');
        check(false, '非法步骤状态应阻止执行');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), '状态不允许执行'), '拒绝原因应为非法状态转换');
    }
    check((string) Db::value('SELECT status FROM ai_workflows WHERE id = ?', [$id]) !== 'completed', '工作流不应被标记完成');
    check(
        (int) Db::value("SELECT COUNT(*) FROM ai_workflow_steps WHERE workflow_id = ? AND status = 'completed'", [$id]) === 1,
        '不应批量把其余步骤标为完成'
    );
});

test('P1：取消工作流时步骤进入 cancelled 并记录转换', function (): void {
    $owner = make_family_user('p1-cancel');
    $familyId = \App\Services\FamilyService::create($owner, '取消状态家族', 'p1-cancel-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1995年，家族建立了第一间作坊。',
    ], 'p1-cancel-' . bin2hex(random_bytes(4)));
    \App\Services\AiWorkflowService::cancel($owner, $id);
    check(
        db_count('ai_workflow_steps', "workflow_id = ? AND status = 'cancelled'", [$id]) === 3,
        '取消时全部步骤应为 cancelled'
    );
    check(
        db_count('ai_workflow_step_transitions', "workflow_id = ? AND new_status = 'cancelled'", [$id]) === 3,
        '取消应记录 3 次步骤转换'
    );
});

test('P1：任务状态 API 返回真实步骤进度与可执行动作', function (): void {
    $owner = make_family_user('p1-api');
    $familyId = \App\Services\FamilyService::create($owner, '状态 API 家族', 'p1-api-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'biography_draft', [
        'text' => '曾祖母生于 1910 年，擅长刺绣。',
    ], 'p1-api-' . bin2hex(random_bytes(4)));
    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $owner['email'], 'password' => 'FamilyTest@123',
    ]));
    check($token !== '', '前置：应取得 token');
    $response = api('GET', '/api/v1/ai/workflows/' . $id, null, $token);
    check($response['status'] === 200, '查询任务状态应 200');
    $data = $response['data']['data'] ?? [];
    check(isset($data['workflow']['step_total']) && (int) $data['workflow']['step_total'] === 3, '应返回步骤总数');
    check(isset($data['workflow']['progress']), '应返回进度');
    check(array_key_exists('can_cancel', $data['workflow']), '应返回 can_cancel');
    check(array_key_exists('deadline_at', $data['workflow']), '应返回 deadline_at');
    check(is_array($data['steps'] ?? null), '应返回步骤列表');
});

echo "\n[Phase 2：工作流截止时间与超时]\n";

test('P2：AI 超时/租约/重试配置有合法上下限', function (): void {
    $specs = [
        ['ai.provider_timeout_seconds', 5, 600],
        ['ai.workflow_timeout_seconds', 60, 86400],
        ['ai.step_timeout_seconds', 5, 3600],
        ['ai.worker_lease_seconds', 60, 7200],
        ['ai.max_retries', 0, 10],
        ['ai.retry_backoff_seconds', 1, 3600],
    ];
    foreach ($specs as [$key, $min, $max]) {
        $value = (int) config($key, -1);
        check($value >= $min && $value <= $max, '配置越界：' . $key . '=' . $value);
    }
});

test('P2：创建任务写入截止时间，状态 API 返回真实 deadline', function (): void {
    $owner = make_family_user('p2-deadline');
    $familyId = \App\Services\FamilyService::create($owner, '截止家族', 'p2-dl-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '家族资料'], 'p2-dl');
    $row = Db::fetch('SELECT * FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
    check($row !== null && !empty($row['deadline_at']), '任务应写入 deadline_at');
    check(strtotime((string) $row['deadline_at']) > time(), '截止时间应在未来');
    check((int) $row['workflow_timeout_seconds'] > 0, '应记录工作流超时预算');

    $token = api_token_of(api('POST', '/api/v1/auth/token', [
        'email' => (string) $owner['email'], 'password' => 'FamilyTest@123',
    ]));
    $detail = api('GET', '/api/v1/ai/workflows/' . $id, null, $token);
    check($detail['status'] === 200, '工作流详情应 200');
    check(($detail['data']['data']['workflow']['deadline_at'] ?? null) === $row['deadline_at'], 'API 应返回真实 deadline_at');
});

test('P2：过期任务不可领取，租约超时回收时按 workflow_timeout 取消', function (): void {
    $owner = make_family_user('p2-expire');
    $familyId = \App\Services\FamilyService::create($owner, '过期家族', 'p2-exp-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '过期资料'], 'p2-exp');
    Db::update('ai_workflows', ['deadline_at' => date('Y-m-d H:i:s', time() - 10)], 'id = ?', [$id]);
    $claimed = \App\Services\AiWorkflowService::claimStrict('p2-worker', 50);
    $ids = array_map('intval', array_column($claimed, 'id'));
    check(!in_array($id, $ids, true), '已过期任务不应被领取');

    Db::update('ai_workflows', [
        'status' => 'processing',
        'locked_by' => 'p2-worker',
        'lease_until' => date('Y-m-d H:i:s', time() - 10),
        'lock_version' => 1,
    ], 'id = ?', [$id]);
    \App\Services\AiWorkflowService::recoverExpired();
    $after = Db::fetch('SELECT status, failure_class FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
    check($after !== null && (string) $after['status'] === 'cancelled', '过期任务应被取消');
    check((string) $after['failure_class'] === 'workflow_timeout', '失败分类应为 workflow_timeout');
});

test('P2：执行期发现全局超时后停止且不写入步骤结果', function (): void {
    $owner = make_family_user('p2-stop');
    $familyId = \App\Services\FamilyService::create($owner, '停止家族', 'p2-stop-' . bin2hex(random_bytes(3)));
    $id = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', ['text' => '停止资料'], 'p2-stop');
    $claimed = \App\Services\AiWorkflowService::claimStrict('p2-worker-b', 50);
    $token = null;
    foreach ($claimed as $row) {
        if ((int) $row['id'] === $id) {
            $token = (int) $row['lock_version'];
            break;
        }
    }
    check($token !== null, '任务应被领取');
    Db::update('ai_workflows', ['deadline_at' => date('Y-m-d H:i:s', time() - 1)], 'id = ?', [$id]);
    try {
        \App\Services\AiWorkflowService::process($id, 'p2-worker-b', $token);
        check(false, '超时任务不应继续完成');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'workflow_timeout'), '拒绝原因应为 workflow_timeout');
    }
    $after = Db::fetch('SELECT status, failure_class, retry_count FROM ai_workflows WHERE id = ? LIMIT 1', [$id]);
    check($after !== null && (string) $after['failure_class'] === 'workflow_timeout', '应记录超时失败分类');
    check((int) $after['retry_count'] >= (int) config('ai.max_retries', 3), '超时任务不得自动重试');
    check(db_count('ai_workflow_steps', "workflow_id = ? AND status = 'completed'", [$id]) === 0, '超时后不得写入已完成步骤');
});

echo "\n[Phase 3：AI 事实来源与可信度]\n";

test('P3：AI 事实默认标记为 AI 推测并写入来源位置', function (): void {
    $owner = make_family_user('p3-fact-kind');
    $familyId = \App\Services\FamilyService::create($owner, '事实类型家族', 'p3-kind-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, [
        'title' => '来源档案', 'privacy' => 'private',
    ]);
    $factId = \App\Services\AiWorkflowService::addFactCandidate(
        $owner, $familyId, 'date', '1990年家族迁徙', 'ai_extraction',
        null, null, 0.65, '档案摘录：1990年家族迁徙', $archiveId, null, 'family', $familyId
    );
    $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
    check($fact !== null, '事实未入库');
    check((string) $fact['fact_kind'] === 'ai_inferred', 'AI 提取必须标记为 ai_inferred');
    check((string) $fact['review_status'] === 'pending', 'AI 事实不得自动审核通过');
    check((string) $fact['source_location'] !== '', '应写入来源位置');
    check((float) $fact['confidence'] < 1.0, 'AI 推测置信度不得为 1');

    $rejected = false;
    try {
        \App\Services\AiWorkflowService::addFactCandidate(
            $owner, $familyId, 'date', '伪造来源', 'ai_extraction',
            999999, null, 0.5, null, 999999, null, 'family', $familyId
        );
    } catch (\App\Core\HttpException $e) {
        $rejected = $e->getStatusCode() === 404;
    }
    check($rejected, '不属于本家族的来源档案必须被拒绝');
});

test('P3：人工审核事实写入版本、意见与修订历史', function (): void {
    $owner = make_family_user('p3-review');
    $familyId = \App\Services\FamilyService::create($owner, '事实审核家族', 'p3-review-' . bin2hex(random_bytes(3)));
    $factId = \App\Services\AiWorkflowService::addFactCandidate(
        $owner, $familyId, 'date', '1992年迁入南方', 'user_input',
        null, null, 0.8, '用户口述', null, null, 'family', $familyId
    );
    \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'approved', '已核对档案');
    $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
    check((string) $fact['verification_status'] === 'confirmed', '审核后应变为 confirmed');
    check((string) $fact['review_status'] === 'approved', 'review_status 应为 approved');
    check((int) $fact['version'] === 2, '版本应递增到 2');
    check(db_count('ai_fact_revisions', 'fact_id = ?', [$factId]) === 1, '应保存一条审核前版本');

    \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'disputed', '发现矛盾，需修订');
    $fact = Db::fetch('SELECT * FROM memory_facts WHERE id = ? LIMIT 1', [$factId]);
    check((string) $fact['review_status'] === 'needs_revision', '争议应进入 needs_revision');
    check((int) $fact['version'] === 3, '再次审核版本应递增到 3');
    check(db_count('ai_fact_revisions', 'fact_id = ?', [$factId]) === 2, '修订历史应保留两条');
});

test('P3：事实审核完成后联动工作流审核步骤与生成日志', function (): void {
    $owner = make_family_user('p3-sync');
    $familyId = \App\Services\FamilyService::create($owner, '审核联动家族', 'p3-sync-' . bin2hex(random_bytes(3)));
    $workflowId = \App\Services\AiWorkflowService::create($owner, $familyId, 'extract_facts', [
        'text' => '1995年家族聚会照片说明。',
    ], 'p3-sync-key');
    \App\Services\AiWorkflowService::process($workflowId, 'p3-worker');
    $step = Db::fetch(
        "SELECT * FROM ai_workflow_steps WHERE workflow_id = ? AND name = 'review_draft' LIMIT 1",
        [$workflowId]
    );
    check($step !== null && (string) $step['status'] === 'awaiting_review', '审核步骤应处于待审核');
    $factId = \App\Services\AiWorkflowService::addFactCandidate(
        $owner, $familyId, 'date', '1995年家族聚会', 'ai_extraction',
        null, null, 0.6, '候选摘录', null, null, 'family', $familyId, $workflowId
    );
    \App\Services\AiWorkflowService::reviewFact($owner, $factId, 'approved', '已核实');
    $step = Db::fetch(
        "SELECT * FROM ai_workflow_steps WHERE workflow_id = ? AND name = 'review_draft' LIMIT 1",
        [$workflowId]
    );
    check((string) $step['status'] === 'approved', '无待审核事实后步骤应 approved');
    check(
        (string) Db::value('SELECT review_status FROM ai_generation_logs WHERE workflow_id = ? ORDER BY id DESC LIMIT 1', [$workflowId]) === 'confirmed',
        '生成日志审核状态应同步 confirmed'
    );
});

echo "\n[Phase 4：文件哈希与处理溯源]\n";

test('P4：JPEG 原始哈希与处理后哈希分别记录且可追溯', function (): void {
    $owner = make_family_user('p4-hash');
    $familyId = \App\Services\FamilyService::create($owner, '哈希家族', 'p4-hash-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '哈希档案', 'privacy' => 'private']);

    $tmp = sys_get_temp_dir() . '/p4-hash-' . bin2hex(random_bytes(4)) . '.jpg';
    $image = imagecreatetruecolor(24, 24);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 60, 30));
    imagejpeg($image, $tmp, 75);
    imagedestroy($image);
    $originalHash = (string) hash_file('sha256', $tmp);

    login((string) $owner['email'], 'FamilyTest@123');
    $upload = http('POST', '/archives/' . $archiveId . '/files', [
        '_token' => csrf_token('/archives/' . $archiveId),
    ], ['file' => $tmp], false);
    check($upload['status'] === 302, '上传应重定向');

    $file = Db::fetch('SELECT * FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    check($file !== null, '缺少档案文件记录');
    check((string) $file['original_sha256'] === $originalHash, '原始哈希必须记录上传前字节');
    check((string) $file['processed_sha256'] === (string) $file['sha256'], '兼容 sha256 应表示处理后哈希');
    check(!empty($file['original_storage_key']) && (int) $file['original_kept'] === 1, '应保留原始文件副本');
    check((string) $file['processing_method'] === 'jpeg_reencode_gd', '处理方式为 GD 重编码');

    $processedPath = \App\Core\Storage::absolute((string) $file['storage_key']);
    $originalPath = \App\Core\Storage::absolute((string) $file['original_storage_key']);
    check($processedPath !== null && $originalPath !== null, '两份物理文件都应存在');
    check((string) hash_file('sha256', (string) $processedPath) === (string) $file['processed_sha256'], '处理后哈希与物理文件一致');
    check((string) hash_file('sha256', (string) $originalPath) === $originalHash, '原始哈希与物理副本一致');
    check(
        db_count('archive_file_processing', 'archive_file_id = ?', [(int) $file['id']]) === 1,
        '应写入处理溯源记录'
    );
    @unlink($tmp);
});

test('P4：伪造图片签名与多态内容被拒绝', function (): void {
    $owner = make_family_user('p4-forged');
    $familyId = \App\Services\FamilyService::create($owner, '伪造家族', 'p4-forged-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '伪造档案', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/p4-forged-' . bin2hex(random_bytes(4)) . '.gif';
    file_put_contents($tmp, "GIF89a<script>alert(1)</script>");

    login((string) $owner['email'], 'FamilyTest@123');
    // 控制器无论成功失败都 PRG 重定向，实际拒绝以数据库无记录为准。
    http('POST', '/archives/' . $archiveId . '/files', [
        '_token' => csrf_token('/archives/' . $archiveId),
    ], ['file' => $tmp], false);
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 0, '伪造图片不应写入文件记录');
    @unlink($tmp);
});

test('P4：归档清理同时删除原始副本与处理后文件', function (): void {
    $owner = make_family_user('p4-clean');
    $familyId = \App\Services\FamilyService::create($owner, '清理哈希家族', 'p4-clean-' . bin2hex(random_bytes(3)));
    $archiveId = \App\Services\ArchiveService::create($owner, $familyId, ['title' => '清理档案', 'privacy' => 'private']);
    $tmp = sys_get_temp_dir() . '/p4-clean-' . bin2hex(random_bytes(4)) . '.jpg';
    $image = imagecreatetruecolor(16, 16);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 160));
    imagejpeg($image, $tmp, 70);
    imagedestroy($image);

    login((string) $owner['email'], 'FamilyTest@123');
    $upload = http('POST', '/archives/' . $archiveId . '/files', [
        '_token' => csrf_token('/archives/' . $archiveId),
    ], ['file' => $tmp], false);
    check($upload['status'] === 302, '上传应成功');
    $file = Db::fetch('SELECT * FROM archive_files WHERE archive_id = ? LIMIT 1', [$archiveId]);
    check($file !== null && !empty($file['original_storage_key']), '应存在原始副本');
    $processed = (string) \App\Core\Storage::absolute((string) $file['storage_key']);
    $original = (string) \App\Core\Storage::absolute((string) $file['original_storage_key']);
    check(is_file($processed) && is_file($original), '清理前两份文件都应存在');

    \App\Services\ArchiveService::requestDelete($owner, $archiveId);
    \App\Services\ArchiveService::approveDelete($owner, $archiveId, true);
    $output = [];
    $rc = 0;
    exec('php ' . escapeshellarg(BASE_PATH . '/bin/archive-cleanup.php') . ' --once --limit=20 2>&1', $output, $rc);
    check($rc === 0, '清理 worker 应成功：' . implode("\n", $output));
    check(!is_file($processed) && !is_file($original), '原始副本与处理后文件都应被清理');
    check(db_count('archive_files', 'archive_id = ?', [$archiveId]) === 0, '文件记录应被清理');
    @unlink($tmp);
});

echo "\n========== 结果 ==========\n";
echo "通过：{$passed}，失败：{$failed}\n";
if ($failed > 0) {
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
exit(0);
