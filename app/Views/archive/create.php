<?php
/** @var array<string,mixed> $family */
/** @var list<string> $errors */
/** @var array<string,mixed> $old */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">数字档案馆</p><h1>新建档案</h1>
    <?php foreach ($errors as $error): ?><div class="banner"><?= e($error) ?></div><?php endforeach; ?>
    <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/archives/new')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="title">档案标题</label>
            <input id="title" name="title" required maxlength="180" value="<?= e((string) ($old['title'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="archive_type">类型</label>
            <select id="archive_type" name="archive_type">
                <?php foreach (['photo' => '照片', 'document' => '文献', 'letter' => '书信', 'certificate' => '证件', 'audio' => '音频', 'video' => '视频', 'other' => '其他'] as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($old['archive_type'] ?? 'document') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="event_date">事件日期</label>
            <input id="event_date" name="event_date" type="date" value="<?= e((string) ($old['event_date'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="location">地点</label>
            <input id="location" name="location" maxlength="180" value="<?= e((string) ($old['location'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="description">说明</label>
            <textarea id="description" name="description" rows="5"><?= e((string) ($old['description'] ?? '')) ?></textarea>
        </div>
        <div class="field">
            <label for="privacy">可见范围</label>
            <select id="privacy" name="privacy">
                <option value="private" <?= ($old['privacy'] ?? 'private') === 'private' ? 'selected' : '' ?>>仅家族管理者</option>
                <option value="family" <?= ($old['privacy'] ?? '') === 'family' ? 'selected' : '' ?>>家族成员</option>
                <option value="public" <?= ($old['privacy'] ?? '') === 'public' ? 'selected' : '' ?>>公开家族可见</option>
            </select>
        </div>
        <button class="button" type="submit">创建档案</button>
    </form>
    <p class="muted small" style="margin-top:1rem;">私密档案默认不公开；公开档案只在家族本身公开时对访客可见。</p>
</div>
