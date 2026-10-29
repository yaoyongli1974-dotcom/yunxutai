<?php
declare(strict_types=1);

/**
 * 轻量集成测试：需要在 bin/test.sh 启动的测试服务器与 jidian_test 数据库下运行。
 * 不依赖 PHPUnit，便于在最小化环境中执行。
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Policy;
use App\Core\Storage;
use App\Core\Str;
use App\Core\Validator;
use App\Models\Media;
use App\Models\Message;
use App\Models\Memorial;
use App\Models\Report;
use App\Models\Tribute;
use App\Models\Verification;

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

echo "静思园 · 集成测试\n";
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

echo "\n========== 结果 ==========\n";
echo "通过：{$passed}，失败：{$failed}\n";
if ($failed > 0) {
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}
exit(0);
