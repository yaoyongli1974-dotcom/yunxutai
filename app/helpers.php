<?php
declare(strict_types=1);

use App\Core\Auth;

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'] ?? [];
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = (string) config('app.url', '');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * 页面规范链接（canonical）：始终使用 APP_URL 中的主机，去掉查询串，
 * 避免 www 与裸域、?page= 等参数产生重复收录。
 */
function canonical_url(?string $path = null): string
{
    if ($path === null) {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    }
    $path = '/' . trim(preg_replace('#/+#', '/', $path), '/');
    $base = rtrim((string) config('app.url', ''), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . ($path === '/' ? '/' : $path);
}

/**
 * 私有/功能性页面：登录、后台、账户等，不应被搜索引擎收录。
 */
function is_private_path(?string $path = null): bool
{
    if ($path === null) {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    }
    foreach (['/admin', '/manage', '/account', '/dashboard', '/login', '/register', '/2fa', '/invitations', '/report', '/media', '/api'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * www 前缀域名应 301 到主域名时返回目标地址，否则返回 null。
 * 主域名取自 APP_URL（含端口），因此反向代理/本地测试同样适用。
 */
function www_redirect_location(): ?string
{
    $base = rtrim((string) config('app.url', ''), '/');
    if ($base === '') {
        return null;
    }
    $appHost = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
    if ($appHost === '') {
        return null;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if ($host !== 'www.' . $appHost) {
        return null;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return $base . ($uri === '' ? '/' : $uri);
}

function asset(string $path): string
{
    $relative = 'assets/' . ltrim($path, '/');
    $file = BASE_PATH . '/public/' . $relative;
    $version = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return url($relative) . '?v=' . rawurlencode($version);
}

function csrf_field(): string
{
    return App\Core\Csrf::field();
}

function old(string $key, mixed $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function auth_user(): ?array
{
    return Auth::user();
}

/**
 * 日期展示：未知日期返回空字符串。
 */
function format_date(?string $date, string $format = 'Y年n月j日'): string
{
    if (!$date || str_starts_with($date, '0000')) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '';
}

function format_datetime(?string $date): string
{
    return format_date($date, 'Y-m-d H:i');
}

function life_dates(array $deceased): string
{
    $birth = format_date($deceased['birth_date'] ?? null, 'Y.m.d');
    $death = format_date($deceased['death_date'] ?? null, 'Y.m.d');
    if ($birth === '' && $death === '') {
        return '';
    }
    return ($birth !== '' ? $birth : '?') . ' — ' . ($death !== '' ? $death : '');
}

function excerpt_text(?string $text, int $limit = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . '……';
}

function visibility_label(string $visibility): string
{
    return match ($visibility) {
        'public' => '公开',
        'unlisted' => '不公开收录',
        'private' => '私密',
        default => $visibility,
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => '草稿',
        'active' => '展示中',
        'suspended' => '已暂停',
        'pending' => '待审核',
        'visible' => '已展示',
        'hidden' => '已隐藏',
        'published' => '已发布',
        'rejected' => '未通过',
        'open' => '待处理',
        'reviewing' => '处理中',
        'resolved' => '已处理',
        'dismissed' => '已驳回',
        'new' => '新申请',
        'contacted' => '已联系',
        'closed' => '已关闭',
        'processing' => '处理中',
        'done' => '已完成',
        default => $status,
    };
}

function role_label(string $role): string
{
    return match ($role) {
        'owner' => '馆主',
        'editor' => '协作者',
        'viewer' => '亲友',
        'admin' => '平台管理员',
        default => $role,
    };
}

function verification_label(string $status): string
{
    return match ($status) {
        'none' => '未提交核验',
        'pending' => '核验中',
        'verified' => '身份材料已核验',
        'rejected' => '核验未通过',
        default => $status,
    };
}

function verification_method_label(string $method): string
{
    if ($method === 'grandfathered') {
        return '历史数据迁移';
    }
    return \App\Models\Verification::METHODS[$method] ?? $method;
}

function report_priority_label(string $priority): string
{
    return match ($priority) {
        'urgent' => '紧急',
        'high' => '较高',
        'normal' => '常规',
        default => $priority,
    };
}

function tribute_label(string $type): string
{
    return match ($type) {
        'flower' => '献花',
        'candle' => '点烛',
        'incense' => '敬香',
        'bow' => '鞠躬',
        default => $type,
    };
}

function tribute_verb(string $type): string
{
    return match ($type) {
        'flower' => '献上一束花',
        'candle' => '点亮一盏烛',
        'incense' => '敬上一炷香',
        'bow' => '深深鞠躬',
        default => '致以纪念',
    };
}

function report_reason_label(string $reason): string
{
    return match ($reason) {
        'fake_person' => '纪念馆信息不实',
        'living_person' => '被祭奠者仍在世',
        'privacy' => '侵犯隐私或肖像权',
        'insult' => '侮辱、诽谤内容',
        'illegal' => '违法违规内容',
        'copyright' => '版权问题',
        'other' => '其他',
        default => $reason,
    };
}

function media_kind_label(string $kind): string
{
    return match ($kind) {
        'photo' => '照片',
        'video' => '视频',
        'audio' => '音频',
        'document' => '文档',
        default => $kind,
    };
}

function anonymous_display(bool|int $anonymous): string
{
    return (bool) $anonymous ? '一位访客' : '';
}

function page_title(string $title): string
{
    $name = (string) config('app.name', '云叙台');
    return $title === '' ? $name : $title . ' · ' . $name;
}

function format_price(int $cents): string
{
    return $cents > 0 ? '¥' . number_format($cents / 100, 2) : '待定';
}

function ai_service_name(string $slug): string
{
    return match ($slug) {
        'photo_restore' => '老照片修复',
        'memorial_video' => '纪念短片',
        'life_article' => '生平文章整理',
        'family_archive' => '家族数字档案',
        default => $slug,
    };
}

function ai_job_status_label(string $status): string
{
    return match ($status) {
        'queued' => '排队中',
        'processing' => '生成中',
        'completed' => '已完成',
        'failed' => '失败',
        default => $status,
    };
}
