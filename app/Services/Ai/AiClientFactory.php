<?php
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

final class AiClientFactory
{
    public static function enabled(): bool
    {
        return (bool) config('ai.enabled', false);
    }

    public static function isRemote(): bool
    {
        return self::enabled() && (string) config('ai.provider') !== 'demo';
    }

    public static function make(): ?AiClient
    {
        if (!self::enabled()) {
            throw new RuntimeException('AI 工作室尚未启用，请联系平台运营。');
        }
        if ((string) config('ai.provider') === 'demo') {
            return null;
        }
        $key = (string) config('ai.api_key');
        if ($key === '') {
            throw new RuntimeException('AI 服务未配置 API Key，请管理员检查环境变量。');
        }
        return new OpenAiClient(
            (string) config('ai.base_url'),
            $key,
            (string) config('ai.text_model'),
            (string) config('ai.image_model'),
            (int) config('ai.timeout', 120)
        );
    }

    public static function label(): string
    {
        if (!self::enabled()) {
            return '未启用';
        }
        return self::isRemote() ? 'AI 服务' : '本地演示';
    }
}
