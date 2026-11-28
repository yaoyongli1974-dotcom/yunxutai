<?php
declare(strict_types=1);

$appEnv = getenv('APP_ENV') ?: '';
$appKey = getenv('APP_KEY');
$appKey = $appKey === false ? '' : $appKey;

// APP_KEY 是媒体签名 URL 的唯一 HMAC 密钥：只有显式声明 local/testing 环境才允许占位符。
// APP_ENV 未设置时按未知环境处理，避免生产漏配环境变量后仍使用可伪造的开发密钥。
if (($appKey === '' || $appKey === 'dev-only-key-change-before-production')
    && !in_array($appEnv, ['local', 'testing'], true)) {
    throw new RuntimeException('APP_KEY 未配置或仍为开发占位符，非本地/测试环境禁止启动；请设置强随机 APP_KEY。');
}
if ($appKey === '' || $appKey === 'dev-only-key-change-before-production') {
    $appKey = 'dev-only-key-change-before-production';
}

// 调试模式默认关闭：本地/测试可显式开启；生产环境开启会泄露堆栈与内部信息，直接拒绝启动。
$appDebug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
if ($appEnv === 'production' && $appDebug) {
    throw new RuntimeException('生产环境禁止开启 APP_DEBUG（调试模式）；请将 APP_DEBUG 设为 false 后再启动。');
}

/** 整数配置：缺省/非法值回退默认值，并强制上下限，避免无限执行或负数。 */
$aiInt = static function (string $name, int $default, int $min, int $max): int {
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($min, min($max, (int) $value));
};
$providerTimeout = $aiInt('AI_PROVIDER_TIMEOUT_SECONDS', (int) (getenv('AI_TIMEOUT') ?: 120), 5, 600);

return [
    'app' => [
        'name'        => getenv('APP_NAME') ?: '云叙台',
        'version'     => getenv('APP_VERSION') ?: 'v2.1.0',
        'tagline'     => '记取来时路，安放未尽言',
        'url'         => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
        'env'         => $appEnv,
        'debug'       => $appDebug,
        'timezone'    => getenv('APP_TIMEZONE') ?: 'Asia/Shanghai',
        'key'         => $appKey,
        // 仅可信反向代理后的 X-Forwarded-Proto 才用于判定 cookie secure。
        'trusted_proxy' => filter_var(getenv('APP_TRUSTED_PROXY') ?: 'false', FILTER_VALIDATE_BOOL),
        'admin_email' => getenv('ADMIN_EMAIL') ?: 'admin@example.com',
        'support_email' => getenv('SUPPORT_EMAIL') ?: 'support@example.com',
    ],
    'mail' => [
        // log=追加邮件文本到日志（默认，开发/兜底）；smtp=真实发信；none=停用。
        'driver'          => getenv('MAIL_DRIVER') ?: 'log',
        'log_path'        => getenv('MAIL_LOG_PATH') ?: BASE_PATH . '/storage/logs/mail.log',
        'from_name'       => getenv('MAIL_FROM_NAME') ?: '云叙台',
        'from_address'    => getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@example.com',
        'smtp_host'       => getenv('MAIL_SMTP_HOST') ?: '',
        'smtp_port'       => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        'smtp_username'   => getenv('MAIL_SMTP_USERNAME') ?: '',
        'smtp_password'   => getenv('MAIL_SMTP_PASSWORD') ?: '',
        'smtp_encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'starttls',
        'smtp_timeout'    => (int) (getenv('MAIL_SMTP_TIMEOUT') ?: 10),
    ],
    'db' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'jidian',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],
    'kv' => [
        // 留空 = MySQL throttle 表 + PHP 文件会话（单机默认）；配置后启用 Redis 限流与共享会话。
        'redis_url'   => trim((string) (getenv('REDIS_URL') ?: '')),
        'rate_driver' => getenv('RATE_DRIVER') ?: 'mysql',
    ],
    'security' => [
        'session_name'       => 'jingsi_session',
        'login_max_attempts' => 5,
        'login_window'       => 900,
        'register_window'    => 3600,
        'post_window'        => 600,
        'captcha_ttl'        => 600,
        'password_min'       => 10,
    ],
    'uploads' => [
        'max_bytes' => 20 * 1024 * 1024,
        'video_max_bytes' => 50 * 1024 * 1024,
        'allowed' => [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp4'  => 'm4a',
            'application/pdf' => 'pdf',
        ],
    ],
    'moderation' => [
        'article_review' => true,
    ],
    'ai' => [
        'enabled' => filter_var(getenv('AI_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
        'provider' => getenv('AI_PROVIDER') ?: 'demo',
        'base_url' => rtrim((string) (getenv('AI_BASE_URL') ?: 'https://api.openai.com/v1'), '/'),
        'api_key' => (string) (getenv('AI_API_KEY') ?: ''),
        'text_model' => getenv('AI_TEXT_MODEL') ?: 'gpt-4.1-mini',
        'image_model' => getenv('AI_IMAGE_MODEL') ?: 'gpt-image-1',
        // 预留：AI 直出视频模型（当前纪念短片由 ffmpeg 本地合成）
        'video_model' => (string) (getenv('AI_VIDEO_MODEL') ?: ''),
        'timeout' => $providerTimeout,
        'provider_timeout_seconds' => $providerTimeout,
        'workflow_timeout_seconds' => $aiInt('AI_WORKFLOW_TIMEOUT_SECONDS', 1800, 60, 86400),
        'step_timeout_seconds' => $aiInt('AI_WORKFLOW_STEP_TIMEOUT_SECONDS', 300, 5, 3600),
        'worker_lease_seconds' => $aiInt('AI_WORKER_LEASE_SECONDS', 900, 60, 7200),
        'max_retries' => $aiInt('AI_WORKFLOW_MAX_RETRIES', 3, 0, 10),
        'retry_backoff_seconds' => $aiInt('AI_WORKFLOW_RETRY_BACKOFF_SECONDS', 60, 1, 3600),
        'daily_limit' => (int) (getenv('AI_DAILY_LIMIT') ?: 10),
        'sync' => filter_var(getenv('AI_SYNC') ?: 'true', FILTER_VALIDATE_BOOL),
        'charging' => filter_var(getenv('AI_CHARGING') ?: 'false', FILTER_VALIDATE_BOOL),
        'ffmpeg' => getenv('FFMPEG_BIN') ?: '/usr/bin/ffmpeg',
    ],
    'storage' => [
        'path' => BASE_PATH . '/storage',
        'uploads_path' => BASE_PATH . '/storage/uploads',
        'verification_path' => BASE_PATH . '/storage/verifications',
    ],
];
