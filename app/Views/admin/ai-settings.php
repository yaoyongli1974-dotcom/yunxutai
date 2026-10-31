<?php
/**
 * 管理后台 · AI 大模型设置。
 *
 * 来源：$cfg（生效值，env 或 DB 设置）、$errors、$old（校验失败回填）。
 * 安全：api_key 仅在已配置时显示掩码提示，表单留空 = 不改动，勾选「清空」= 回退环境配置。
 */

use App\Core\Csrf;

/** @var array $cfg */
/** @var array $old */
/** @var array $errors */
$presets = [
    'demo' => '本地演示模式：GD 增强、模板化文案，不需要外部 API。',
    'openai' => 'OpenAI 兼容 API：照片修复走图像接口，文本走对话接口。',
];
$provider = (string) ($old['provider'] ?? $cfg['provider']);
$sync = (bool) ($old['sync'] ?? $cfg['sync']);
$enabled = (bool) ($old['enabled'] ?? $cfg['enabled']);
$baseUrl = (string) ($old['base_url'] ?? $cfg['base_url']);
$textModel = (string) ($old['text_model'] ?? $cfg['text_model']);
$imageModel = (string) ($old['image_model'] ?? $cfg['image_model']);
?>
<section class="admin-section">
    <p class="eyebrow">AI 大模型设置</p>
    <h2>运行模式与凭据</h2>
    <p class="muted small">此项决定「馆内 AI 工作室」四项能力的真实执行方式。保存后立即生效，密钥只会保存在服务器数据库和 .env 中，不会回传页面。</p>

    <?php if (($errors ?? []) !== []): ?>
        <div class="alert alert--error">
            <ul>
                <?php foreach ($errors as $message): ?>
                    <li><?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/ai-settings" class="admin-form" novalidate>
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">

        <label class="field">
            <span class="field__label">启用 AI 工作室</span>
            <select name="enabled" id="ai-enabled">
                <option value="1" <?= $enabled ? 'selected' : '' ?>>启用</option>
                <option value="0" <?= !$enabled ? 'selected' : '' ?>>停用（馆主端入口提示未开放）</option>
            </select>
        </label>

        <fieldset class="field">
            <legend class="field__label">运行模式</legend>
            <?php foreach ($presets as $value => $hint): ?>
                <label class="radio-row">
                    <input type="radio" name="provider" value="<?= e($value) ?>" <?= $provider === $value ? 'checked' : '' ?>>
                    <strong><?= $value === 'demo' ? 'demo · 本地演示' : 'openai · 大模型 API' ?></strong>
                    <span class="muted small"><?= e($hint) ?></span>
                </label>
            <?php endforeach; ?>
            <?php if (isset($errors['provider'])): ?><p class="field__error"><?= e($errors['provider']) ?></p><?php endif; ?>
        </fieldset>

        <label class="field">
            <span class="field__label">API 基础地址（可选，默认 https://api.openai.com/v1）</span>
            <input type="url" name="base_url" id="ai-base-url" value="<?= e($baseUrl) ?>" placeholder="https://api.openai.com/v1">
            <?php if (isset($errors['base_url'])): ?><p class="field__error"><?= e($errors['base_url']) ?></p><?php endif; ?>
        </label>

        <label class="field">
            <span class="field__label">
                API Key
                <?php if ($cfg['api_key_set']): ?>
                    <em class="muted small">（已配置，来自<?= e($cfg['api_key_from']) ?> · ****<?= e($cfg['api_key_tail'] ?? '') ?>）</em>
                <?php else: ?>
                    <em class="muted small">（未配置）</em>
                <?php endif; ?>
            </span>
            <input type="password" name="api_key" id="ai-api-key" value="" autocomplete="off" placeholder="<?= $cfg['api_key_set'] ? '留空则保持现有密钥' : 'sk-…' ?>">
            <?php if (isset($errors['api_key'])): ?><p class="field__error"><?= e($errors['api_key']) ?></p><?php endif; ?>
        </label>

        <label class="checkbox-row">
            <input type="checkbox" name="clear_key" value="1">
            <span class="muted small">清空 Key（回退到 .env 配置或未配置状态）</span>
        </label>

        <div class="grid-2">
            <label class="field">
                <span class="field__label">文本模型（文章草稿 / 生平整理）</span>
                <input type="text" name="text_model" value="<?= e($textModel) ?>" placeholder="gpt-4.1-mini" maxlength="80">
                <?php if (isset($errors['text_model'])): ?><p class="field__error"><?= e($errors['text_model']) ?></p><?php endif; ?>
            </label>
            <label class="field">
                <span class="field__label">图像模型（老照片修复）</span>
                <input type="text" name="image_model" value="<?= e($imageModel) ?>" placeholder="gpt-image-1" maxlength="80">
                <?php if (isset($errors['image_model'])): ?><p class="field__error"><?= e($errors['image_model']) ?></p><?php endif; ?>
            </label>
        </div>

        <div class="grid-2">
            <label class="field">
                <span class="field__label">接口超时（秒，10–600）</span>
                <input type="number" name="timeout" value="<?= (int) ($old['timeout'] ?? $cfg['timeout']) ?>" min="10" max="600">
                <?php if (isset($errors['timeout'])): ?><p class="field__error"><?= e($errors['timeout']) ?></p><?php endif; ?>
            </label>
            <label class="field">
                <span class="field__label">每人每馆每日任务上限（1–100）</span>
                <input type="number" name="daily_limit" value="<?= (int) ($old['daily_limit'] ?? $cfg['daily_limit']) ?>" min="1" max="100">
                <?php if (isset($errors['daily_limit'])): ?><p class="field__error"><?= e($errors['daily_limit']) ?></p><?php endif; ?>
            </label>
        </div>

        <label class="field">
            <span class="field__label">任务执行方式</span>
            <select name="sync">
                <option value="1" <?= $sync ? 'selected' : '' ?>>同步完成（请求内执行，适合演示/低并发）</option>
                <option value="0" <?= !$sync ? 'selected' : '' ?>>异步队列（php bin/ai-worker.php 消费，适合生产）</option>
            </select>
        </label>

        <button type="submit" class="btn btn--primary">保存 AI 设置</button>
    </form>

    <div class="muted small admin-note">
        <p>提示：openai 模式会把照片与生平文本发送到所配置的第三方服务，发送前需告知家属并取得同意；不发送身份核验材料、身份证号和联系方式。</p>
        <p>当前任务：<strong><?= (int) $jobsTotal ?></strong> 条（失败 <strong><?= (int) $jobsFailed ?></strong> 条），在「AI 任务」页可查看详情与重试。</p>
    </div>
</section>
