<?php
declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * AI 客户端工厂。
 *
 * 双凭证模型：
 *   - 主组 = ai.provider/base_url/api_key/text_model/image_model（文本与图像共用）；
 *   - 视频组 = ai.video.provider/base_url/api_key/model（当 ai.video.model 或任一键存在时生效，
 *     未写的键按 provider/base/key→主组、model→ai.video_model 回退）。
 * make(string $domain)：'text' / 'image' 均走主组；'video' 走视频组（当前仅预留，无外部调用）。
 */
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

    /**
     * 按域解析生效配置。
     *
     * @return array{provider:string, base_url:string, api_key:string, model:string}
     */
    public static function resolve(string $domain): array
    {
        if ($domain === 'text' || $domain === 'image') {
            // 主组：文本/图像共用同一套凭证；model 按域读取。
            return [
                'provider' => (string) config('ai.provider', 'demo'),
                'base_url' => (string) config('ai.base_url', ''),
                'api_key' => (string) config('ai.api_key', ''),
                'model' => (string) config($domain === 'text' ? 'ai.text_model' : 'ai.image_model', ''),
            ];
        }
        if ($domain !== 'video') {
            throw new RuntimeException('未知的 AI 域：' . $domain);
        }
        // 视频组：嵌套 ai.video.*，缺失键回退主组/url→ai.base_url、key→ai.api_key、model→ai.video_model。
        $provider = (string) (config('ai.video.provider', '') ?: config('ai.provider', 'demo'));
        $apiBase = (string) (config('ai.video.base_url', '') ?: config('ai.base_url', ''));
        $apiKey = (string) (config('ai.video.api_key', '') ?: config('ai.api_key', ''));
        $model = (string) (config('ai.video.model', '') ?: config('ai.video_model', ''));
        return [
            'provider' => $provider,
            'base_url' => rtrim($apiBase, '/'),
            'api_key' => $apiKey,
            'model' => $model,
        ];
    }

    public static function make(): ?AiClient
    {
        return self::makeFor('text');
    }

    /**
     * 供旧调用面默认（文本链路）与按域调用。
     */
    public static function makeFor(string $domain): ?AiClient
    {
        if (!self::enabled()) {
            throw new RuntimeException('AI 工作室尚未启用，请联系平台运营。');
        }
        $cfg = self::resolve($domain);
        if ($cfg['provider'] === 'demo') {
            return null;
        }
        if ($cfg['api_key'] === '') {
            throw new RuntimeException('AI 服务未配置 API Key，请管理员在后台「AI 大模型设置」补全。');
        }
        if ($cfg['base_url'] === '' || $cfg['model'] === '') {
            throw new RuntimeException('AI 服务缺少基础地址或模型名，请管理员在后台补全。');
        }
        return new OpenAiClient(
            $cfg['base_url'],
            $cfg['api_key'],
            $domain === 'text' ? $cfg['model'] : (string) config('ai.text_model'),
            $domain === 'text' ? (string) config('ai.image_model') : $cfg['model'],
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
