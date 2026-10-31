<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 后台平台设置：DB 键值（platform_settings）按白名单合并到 $GLOBALS['config']。
 *
 * 语义：后台设置覆盖 .env 默认值；未写过的键保持 env 原值。
 * 当前仅开放 AI 配置键位（ai.*）。支持测试注入 overrides 与 reset()。
 */
final class Settings
{
    /** @var array<string,string> 支持后台覆盖的 config key 白名单 */
    private const AI_KEYS = [
        'ai.enabled',
        'ai.provider',
        'ai.base_url',
        'ai.api_key',
        'ai.text_model',
        'ai.image_model',
        'ai.timeout',
        'ai.daily_limit',
        'ai.sync',
        'ai.ffmpeg',
    ];

    /** @var array<string,mixed>|null 缓存：name => raw value（null=未缓存） */
    private static ?array $cache = null;

    /** @var array<string,string> 测试注入覆盖（优先于 DB） */
    private static array $overrides = [];

    /** 是否已经把 DB 值合并进 $GLOBALS['config'] */
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        try {
            $rows = Db::fetchAll('SELECT name, value FROM platform_settings');
        } catch (\Throwable) {
            $rows = [];
        }
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['name']] = (string) $row['value'];
        }
        foreach (self::$overrides as $name => $value) {
            if ($value !== null) {
                $values[$name] = $value;
            } else {
                unset($values[$name]);
            }
        }
        self::$cache = array_filter($values, static fn ($v) => $v !== null);
        self::applyToConfig($values);
    }

    private static function applyToConfig(array $values): void
    {
        $config = $GLOBALS['config'] ?? null;
        if (!is_array($config) || !isset($config['ai']) || !is_array($config['ai'])) {
            return;
        }
        foreach (self::AI_KEYS as $key) {
            $raw = $values[$key] ?? null;
            if ($raw === null) {
                continue;
            }
            [, $field] = explode('.', $key, 2);
            switch ($field) {
                case 'enabled':
                case 'sync':
                    $config['ai'][$field] = filter_var($raw, FILTER_VALIDATE_BOOL);
                    break;
                case 'timeout':
                case 'daily_limit':
                    $config['ai'][$field] = (int) $raw;
                    break;
                default:
                    $config['ai'][$field] = $raw;
            }
        }
        $GLOBALS['config'] = $config;
    }

    /**
     * 读取 DB 值（缓存；含 overrides）。未配置返回 $default。
     */
    public static function get(string $name, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($name, self::$overrides)) {
            return self::$overrides[$name];
        }
        $raw = self::$cache !== null ? (self::$cache[$name] ?? null) : null;
        return $raw !== null ? $raw : $default;
    }

    /**
     * 写入或删除（$value=null）。命名必须命中白名单；成功返回 true。
     */
    public static function set(string $name, ?string $value): bool
    {
        if (!in_array($name, self::AI_KEYS, true)) {
            return false;
        }
        if ($value === null) {
            Db::query('DELETE FROM platform_settings WHERE name = ?', [$name]);
        } else {
            Db::query(
                'INSERT INTO platform_settings (name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$name, $value]
            );
        }
        self::$overrides[$name] = $value;
        self::applyToConfig(array_merge(self::$cache ?? [], self::$overrides));
        return true;
    }

    public static function all(): array
    {
        self::load();
        return self::$cache ?? [];
    }

    /** 重置缓存与覆盖（测试用）；调用方随后可自行注入 overrides。 */
    public static function reset(array $overrides = []): void
    {
        self::$cache = null;
        self::$overrides = $overrides;
        self::$loaded = false;
    }
}
