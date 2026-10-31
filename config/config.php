<?php
declare(strict_types=1);

$appKey = getenv('APP_KEY');
if ($appKey === false || $appKey === '') {
    $appKey = 'dev-only-key-change-before-production';
}

return [
    'app' => [
        'name'        => getenv('APP_NAME') ?: '云叙台',
        'tagline'     => '记取来时路，安放未尽言',
        'url'         => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
        'env'         => getenv('APP_ENV') ?: 'local',
        'debug'       => filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL),
        'timezone'    => getenv('APP_TIMEZONE') ?: 'Asia/Shanghai',
        'key'         => $appKey,
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
        'timeout' => (int) (getenv('AI_TIMEOUT') ?: 120),
        'daily_limit' => (int) (getenv('AI_DAILY_LIMIT') ?: 10),
        'sync' => filter_var(getenv('AI_SYNC') ?: 'true', FILTER_VALIDATE_BOOL),
        'ffmpeg' => getenv('FFMPEG_BIN') ?: '/usr/bin/ffmpeg',
    ],
    'storage' => [
        'path' => BASE_PATH . '/storage',
        'uploads_path' => BASE_PATH . '/storage/uploads',
        'verification_path' => BASE_PATH . '/storage/verifications',
    ],
];
