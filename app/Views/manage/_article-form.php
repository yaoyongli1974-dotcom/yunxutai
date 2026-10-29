<?php
$articleValues = is_array($article ?? null) ? $article : [];
$formValue = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $articleValues[$key] ?? $default);
$currentStatus = (string) ($articleValues['status'] ?? '');
$actionValue = (string) ($old['action'] ?? ($currentStatus === 'draft' ? 'draft' : 'publish'));
?>
<?php if ($isEdit): ?>
    <p class="muted" style="margin-bottom:0.9rem;">
        当前状态：<span class="manage-badge manage-badge--<?= e($currentStatus) ?>"><?= e(status_label($currentStatus)) ?></span>
        <?php if (!empty($articleValues['review_note'])): ?>
            <br>审核说明：<?= e($articleValues['review_note']) ?>
        <?php endif; ?>
    </p>
<?php endif; ?>

<form class="form manage-form" method="post" action="<?= e($formAction) ?>">
    <?= csrf_field() ?>

    <label class="field">
        <span>文章标题</span>
        <input type="text" name="title" value="<?= e($formValue('title')) ?>" maxlength="190" required>
        <?php if (!empty($errors['title'])): ?><small class="field__error"><?= e($errors['title']) ?></small><?php endif; ?>
    </label>

    <label class="field">
        <span>作者署名（可选）</span>
        <input type="text" name="author_name" value="<?= e($formValue('author_name')) ?>" maxlength="80" placeholder="例如：女儿 张宁">
        <small class="field__hint">可以写亲属关系与姓名，也可以留空。</small>
        <?php if (!empty($errors['author_name'])): ?><small class="field__error"><?= e($errors['author_name']) ?></small><?php endif; ?>
    </label>

    <label class="field">
        <span>正文</span>
        <textarea name="content" maxlength="50000" style="min-height:280px;" required><?= e($formValue('content')) ?></textarea>
        <small class="field__hint">20–50000 字。支持普通段落换行。</small>
        <?php if (!empty($errors['content'])): ?><small class="field__error"><?= e($errors['content']) ?></small><?php endif; ?>
    </label>

    <label class="field">
        <span>保存方式</span>
        <select name="action">
            <option value="draft"<?= $actionValue === 'draft' ? ' selected' : '' ?>>保存草稿（不公开）</option>
            <option value="publish"<?= $actionValue === 'publish' ? ' selected' : '' ?>><?= $reviewRequired ? '提交审核（通过后公开）' : '发布文章' ?></option>
        </select>
        <small class="field__hint">
            <?php if ($reviewRequired): ?>
                平台会对纪念文章做一次内容审核。提交后状态为“待审核”，通过后才会出现在公开页面。
            <?php else: ?>
                发布后会立即出现在纪念馆的“纪念文章”页面。
            <?php endif; ?>
            将已发布文章改为草稿会暂时从公开页面移除。
        </small>
    </label>

    <div class="manage-actions-row">
        <button class="button" type="submit"><?= $isEdit ? '保存文章' : '创建文章' ?></button>
        <a class="text-link" href="<?= e(url('/manage/memorials/' . (int) $memorial['id'] . '/articles')) ?>">返回文章列表</a>
    </div>
</form>
