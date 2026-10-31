<?php
/**
 * 管理后台 · AI 大模型设置。
 *
 * 来源：$cfg（生效值，env 或 DB 设置）、$errors、$old（校验失败回填）。
 * 安全：api_key 仅在已配置时显示掩码提示，表单留空 = 不改动，勾选「清空」= 回退环境配置。
 * 「获取模型列表」通过 POST /admin/ai-settings/models 由服务端携带已存密钥请求
 * {base_url}/models，响应仅包含模型 ID 列表；Key 不回传浏览器。
 */

use App\Core\Csrf;

/** @var array $cfg */
/** @var array $old */
/** @var array $errors */
$presets = [
    'demo' => '本地演示模式：GD 增强、模板化文案，不需要外部 API。',
    'openai' => 'OpenAI 兼容 API：照片修复走图像接口，文本/视频走对话与生成接口。',
];
$field = static function (string $key, string $fallback = '') use ($old, $cfg): string {
    $value = array_key_exists($key, $old) ? (string) ($old[$key] ?? '') : (string) ($cfg[$key] ?? $fallback);
    return $value !== '' ? $value : (array_key_exists($key, $old) ? '' : $fallback);
};
$err = static fn (string $key): string => isset($errors[$key]) ? '<p class="field__error">' . e((string) $errors[$key]) . '</p>' : '';
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'ai-settings']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">AI 大模型设置</p>
                    <h1>运行模式与凭据</h1>
                    <p class="muted small">决定「馆内 AI 工作室」四项能力的真实执行方式。保存后立即生效；密钥只保存在服务器，不会回传页面。</p>
                </div>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/ai-jobs')) ?>">查看 AI 任务</a>
            </div>

            <?php if (($errors ?? []) !== []): ?>
                <div class="admin-alert admin-alert--error">
                    <ul>
                        <?php foreach (($errors ?? []) as $message): ?>
                            <li><?= e($message) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="admin-panel">
                <form method="post" action="/admin/ai-settings" novalidate>
                    <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">

                    <label class="field">
                        <span class="field__label">启用 AI 工作室</span>
                        <select name="enabled">
                            <option value="1" <?= (bool) ($old['enabled'] ?? $cfg['enabled']) ? 'selected' : '' ?>>启用</option>
                            <option value="0" <?= !(bool) ($old['enabled'] ?? $cfg['enabled']) ? 'selected' : '' ?>>停用</option>
                        </select>
                    </label>

                    <fieldset class="field">
                        <legend class="field__label">运行模式</legend>
                        <?php foreach ($presets as $value => $hint): ?>
                            <label class="radio-row">
                                <input type="radio" name="provider" value="<?= e($value) ?>" <?= $field('provider', 'demo') === $value ? 'checked' : '' ?>>
                                <strong><?= $value === 'demo' ? 'demo · 本地演示' : 'openai · 兼容 API' ?></strong>
                                <span class="muted small"><?= e($hint) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?= $err('provider') ?>
                    </fieldset>

                    <label class="field">
                        <span class="field__label">API 基础地址（默认 https://api.openai.com/v1）</span>
                        <input type="url" name="base_url" id="ai-base-url" value="<?= e($field('base_url')) ?>" placeholder="https://api.openai.com/v1">
                        <?= $err('base_url') ?>
                        <button type="button" class="button button--small button--ghost" id="ai-fetch-models">获取模型列表</button>
                        <span id="ai-models-status" class="muted small" aria-live="polite"></span>
                    </label>

                    <label class="field">
                        <span class="field__label">
                            API Key
                            <?php if ($cfg['api_key_set']): ?>
                                <em class="muted small">（已配置，来自<?= e($cfg['api_key_from']) ?> · ****<?= e($cfg['api_key_tail']) ?>）</em>
                            <?php else: ?>
                                <em class="muted small">（未配置）</em>
                            <?php endif; ?>
                        </span>
                        <input type="password" name="api_key" value="" autocomplete="off" placeholder="<?= $cfg['api_key_set'] ? '留空则保持现有密钥' : 'sk-…' ?>">
                        <label class="checkbox-row">
                            <input type="checkbox" name="clear_key" value="1">
                            <span class="muted small">清空 Key（下次请求不再携带，回退 .env 配置）</span>
                        </label>
                        <?= $err('api_key') ?>
                    </label>

                    <datalist id="ai-model-options"></datalist>

                    <div class="grid-2">
                        <label class="field">
                            <span class="field__label">文本模型（文章草稿 / 生平整理）</span>
                            <input type="text" name="text_model" list="ai-model-options" value="<?= e($field('text_model')) ?>" placeholder="gpt-4.1-mini" maxlength="80">
                            <?= $err('text_model') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">图像模型（老照片修复）</span>
                            <input type="text" name="image_model" list="ai-model-options" value="<?= e($field('image_model')) ?>" placeholder="gpt-image-1" maxlength="80">
                            <?= $err('image_model') ?>
                        </label>
                    </div>

                    <fieldset class="field admin-subpanel ai-video-group">
                        <legend class="field__label">视频独立凭证（预留，可选）</legend>
                        <p class="muted small">留空即可完全跟随上方主凭证。纪念短片目前仍由 ffmpeg 本地合成，本组用于未来接入 AI 直出视频服务时单独配置地址 / Key / 模型。</p>
                        <label class="field">
                            <span class="field__label">视频组运行模式（当前生效：<?= e($cfg['video_provider']) ?>）</span>
                            <select name="video_provider">
                                <option value="" <?= ($old['video_provider'] ?? $cfg['video_provider_custom']) === '' ? 'selected' : '' ?>>跟随主组运行模式</option>
                                <option value="demo" <?= ($old['video_provider'] ?? $cfg['video_provider_custom']) === 'demo' ? 'selected' : '' ?>>demo · 强制本地/演示</option>
                                <option value="openai" <?= ($old['video_provider'] ?? $cfg['video_provider_custom']) === 'openai' ? 'selected' : '' ?>>openai · 独立 API</option>
                            </select>
                            <?= $err('video_provider') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">
                                视频 API 基础地址
                                <em class="muted small">（生效：<?= e($cfg['video_base_url_effective'] !== '' ? $cfg['video_base_url_effective'] : '跟随主组') ?>）</em>
                            </span>
                            <input type="url" name="video_base_url" id="ai-video-base-url" value="<?= e(($old['video_base_url'] ?? '') !== '' ? $old['video_base_url'] : (string) ($cfg['video_base_url'] ?? '')) ?>" placeholder="<?= e($cfg['video_base_url_effective'] !== '' ? $cfg['video_base_url_effective'] : 'https://api.openai.com/v1') ?>">
                            <button type="button" class="button button--small button--ghost" id="ai-fetch-video-models">获取视频模型列表</button>
                            <span id="ai-video-models-status" class="muted small" aria-live="polite"></span>
                            <?= $err('video_base_url') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">
                                视频 API Key
                                <em class="muted small">（当前：<?= $cfg['video_key_set'] ? '已配置，来自' . e($cfg['video_key_from']) . ' · ****' . e($cfg['video_key_tail']) : '未配置（请求时跟随主组）' ?>）</em>
                            </span>
                            <input type="password" name="video_api_key" value="" autocomplete="off" placeholder="<?= $cfg['video_key_set'] ? '留空则保持现有密钥' : '留空跟随主组 Key；或独立填写' ?>">
                            <label class="checkbox-row">
                                <input type="checkbox" name="clear_video" value="1">
                                <span class="muted small">清空独立视频组（provider/地址/Key/模型全部回退主组）</span>
                            </label>
                            <?= $err('video_api_key') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">
                                视频模型
                                <em class="muted small">（生效：<?= e($cfg['video_model_effective'] !== '' ? $cfg['video_model_effective'] : '未设置') ?>）</em>
                            </span>
                            <input type="text" name="video_model" list="ai-video-model-options" value="<?= e(($old['video_model'] ?? '') !== '' ? $old['video_model'] : (string) ($cfg['video_model'] ?? '')) ?>" placeholder="如 sora-2 / 国内视频生成模型 ID" maxlength="80">
                            <?= $err('video_model') ?>
                        </label>
                        <datalist id="ai-video-model-options"></datalist>
                    </fieldset>

                    <div class="grid-2">
                        <label class="field">
                            <span class="field__label">接口超时（秒，10–600）</span>
                            <input type="number" name="timeout" value="<?= (int) $field('timeout', '120') ?>" min="10" max="600">
                            <?= $err('timeout') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">每人每馆每日任务上限（1–100）</span>
                            <input type="number" name="daily_limit" value="<?= (int) $field('daily_limit', '10') ?>" min="1" max="100">
                            <?= $err('daily_limit') ?>
                        </label>
                        <label class="field">
                            <span class="field__label">任务执行方式</span>
                            <select name="sync">
                                <option value="1" <?= (bool) ($old['sync'] ?? $cfg['sync']) ? 'selected' : '' ?>>同步完成（请求内执行，适合演示/低并发）</option>
                                <option value="0" <?= !(bool) ($old['sync'] ?? $cfg['sync']) ? 'selected' : '' ?>>异步队列（php bin/ai-worker.php 消费，适合生产）</option>
                            </select>
                        </label>
                    </div>

                    <button type="submit" class="btn btn--primary">保存 AI 设置</button>
                </form>

                <div class="muted small admin-note">
                    <p>提示：openai 模式会把照片与生平文本发送到所配置的第三方服务，发送前需告知家属并取得同意；不发送身份核验材料、身份证号和联系方式。</p>
                    <p>当前任务：<strong><?= (int) $jobsTotal ?></strong> 条（失败 <strong><?= (int) $jobsFailed ?></strong> 条），在「AI 任务」页可查看详情与重试。</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="/assets/js/admin-ai-settings.js" defer></script>
