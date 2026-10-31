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
    $limitKeys = array_unique(['2fa:' . $host . ':' . $adminId, '2fa:127.0.0.1:' . $adminId, '2fa:::1:' . $adminId]);
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

echo "\n========== 结果 ==========\n";
echo "通过：{$passed}，失败：{$failed}\n";
if ($failed > 0) {
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
exit(0);
