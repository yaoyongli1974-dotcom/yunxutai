<?php
/** @var array<string,mixed> $archive */
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $files */
/** @var list<array<string,mixed>> $tags */
/** @var list<array<string,mixed>> $persons */
/** @var list<array<string,mixed>> $allPersons */
/** @var list<array<string,mixed>> $versions */
/** @var bool $canEdit */
/** @var bool $canUpload */
/** @var bool $canDelete */
/** @var bool $canApprove */
/** @var bool $canExport */
/** @var array<int,list<array<string,mixed>>> $photoTags */
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow"><a class="text-link" href="<?= e(url('/families/' . (int) $family['id'] . '/archives')) ?>">数字档案馆</a></p>
    <div class="section-heading section-heading--row">
        <div>
            <h1><?= e($archive['title']) ?></h1>
            <?php if ($canExport): ?><p><a class="text-link" href="<?= e(url('/archives/' . (int) $archive['id'] . '/export')) ?>">导出档案数据包（JSON）</a></p><?php endif; ?>
            <p class="muted small"><?= e(format_date($archive['event_date'] ?? null)) ?> · <?= e($archive['location'] ?? '') ?> · 可见范围：<?= e($archive['privacy']) ?></p>
        </div>
    </div>
    <?php if ($archive['description']): ?><div class="notice-card"><p><?= nl2br(e($archive['description'])) ?></p></div><?php endif; ?>
    <?php if ($archive['status'] === 'delete_requested'): ?><div class="banner">该档案有待审核的删除申请。</div><?php endif; ?>

    <section class="section section--tight">
        <h2>档案文件</h2>
        <?php if ($files === []): ?><div class="empty"><p>尚未上传文件。</p></div><?php endif; ?>
        <table class="table">
            <?php foreach ($files as $file): ?>
                <tr>
                    <td><?= e($file['original_filename']) ?></td>
                    <td class="muted small"><?= e($file['mime_type']) ?> · <?= (int) ceil((int) $file['file_size'] / 1024) ?> KB</td>
                    <td class="muted small"><?= e($file['file_role']) ?></td>
                    <td><a class="text-link" href="<?= e(url('/archives/files/' . (int) $file['id'] . '/download')) ?>">下载</a></td>
                </tr>
                <tr><td colspan="4" class="muted small" style="word-break:break-all;">SHA-256 <?= e($file['sha256']) ?></td></tr>
                <?php if (str_starts_with((string) $file['mime_type'], 'image/')): ?>
                    <tr><td colspan="4">
                        <?php if (($photoTags[(int) $file['id']] ?? []) !== []): ?>
                            <div class="chip">已标注 <?= count($photoTags[(int) $file['id']]) ?> 处</div>
                            <?php foreach ($photoTags[(int) $file['id']] as $tag): ?>
                                <span class="chip"><?= e($tag['person_name']) ?> · <?= e($tag['review_status']) ?></span>
                                <?php if ($canEdit && $tag['review_status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(url('/archives/tags/' . (int) $tag['id'] . '/review')) ?>" style="display:inline;">
                                        <?= csrf_field() ?><input type="hidden" name="archive_id" value="<?= (int) $archive['id'] ?>">
                                        <button class="button button--small" name="status" value="confirmed" type="submit">确认</button>
                                        <button class="button button--small button--ghost" name="status" value="rejected" type="submit">驳回</button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <img class="photo-tag-preview" src="<?= e(url('/archives/files/' . (int) $file['id'] . '/download')) ?>" alt="标注预览" style="max-width:100%;max-height:260px;display:block;margin:0.5rem 0;">
                        <?php if ($canEdit): ?>
                            <form method="post" class="form photo-tag-form" data-photo-tag action="<?= e(url('/archives/' . (int) $archive['id'] . '/files/' . (int) $file['id'] . '/tags')) ?>">
                                <?= csrf_field() ?>
                                <label class="field">标注人物
                                    <select name="person_id"><?php foreach ($allPersons as $person): ?><option value="<?= (int) $person['id'] ?>"><?= e($person['name']) ?></option><?php endforeach; ?></select>
                                </label>
                                <input type="hidden" name="x" value="10"><input type="hidden" name="y" value="10">
                                <input type="hidden" name="width" value="20"><input type="hidden" name="height" value="20">
                                <button class="button button--small button--ghost" type="submit">添加区域标注（默认左上 20% 区域）</button>
                            </form>
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </table>
        <?php if ($canUpload): ?>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('/archives/' . (int) $archive['id'] . '/files')) ?>" class="form" style="margin-top:1rem;">
                <?= csrf_field() ?>
                <div class="field"><label for="file">上传原始文件（不会覆盖已有文件）</label><input id="file" name="file" type="file" required></div>
                <button class="button button--small" type="submit">上传并归档</button>
            </form>
            <div class="batch-panel" data-batch-panel hidden>
                <h3>批量归档</h3>
                <p class="muted small">一次选择多个文件，按选择顺序逐个上传；失败的文件可单独重试。</p>
                <form method="post" enctype="multipart/form-data" action="<?= e(url('/archives/' . (int) $archive['id'] . '/files')) ?>" class="batch-form" data-batch-upload data-batch-results="#archive-batch-results">
                    <?= csrf_field() ?>
                    <label class="field batch-form__file">
                        <span>选择多个文件</span>
                        <input type="file" name="file[]" multiple required>
                    </label>
                    <div><button class="button button--small" type="submit">按顺序归档</button></div>
                </form>
                <p class="batch-summary" data-batch-summary role="status" aria-live="polite"></p>
                <ul class="batch-results" id="archive-batch-results" aria-live="polite"></ul>
                <p class="muted small">上传完成后刷新页面即可看到新文件；上方的单个上传表单始终可用。</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="section section--tight">
        <h2>关联人物与标签</h2>
        <p><?php foreach ($persons as $person): ?><span class="chip"><?= e($person['name']) ?></span><?php endforeach; ?>
        <?php foreach ($tags as $tag): ?><span class="chip"><?= e($tag['name']) ?></span><?php endforeach; ?>
        <?php if ($persons === [] && $tags === []): ?><span class="muted">暂无关联</span><?php endif; ?></p>
        <?php if ($canEdit): ?>
            <div class="grid grid--2">
                <form method="post" action="<?= e(url('/archives/' . (int) $archive['id'] . '/tags')) ?>" class="form">
                    <?= csrf_field() ?>
                    <div class="field"><label for="tag">添加标签</label><input id="tag" name="name" maxlength="60" required></div>
                    <button class="button button--ghost button--small" type="submit">添加</button>
                </form>
                <?php if ($allPersons !== []): ?>
                    <form method="post" action="<?= e(url('/archives/' . (int) $archive['id'] . '/persons')) ?>" class="form">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="person">关联人物</label>
                            <select id="person" name="person_id">
                                <?php foreach ($allPersons as $person): ?><option value="<?= (int) $person['id'] ?>"><?= e($person['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button class="button button--ghost button--small" type="submit">关联</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canEdit): ?>
        <section class="section section--tight">
            <h2>编辑与版本</h2>
            <form method="post" action="<?= e(url('/archives/' . (int) $archive['id'] . '/edit')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field"><label for="title">标题</label><input id="title" name="title" value="<?= e($archive['title']) ?>" required></div>
                <div class="field"><label for="description">说明</label><textarea id="description" name="description" rows="4"><?= e($archive['description'] ?? '') ?></textarea></div>
                <div class="field">
                    <label for="privacy">可见范围</label>
                    <select id="privacy" name="privacy">
                        <?php foreach (['private' => '仅家族管理者', 'family' => '家族成员', 'public' => '公开家族可见'] as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $archive['privacy'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="change_note">本次修改说明</label><input id="change_note" name="change_note" maxlength="255"></div>
                <button class="button button--small" type="submit">保存为新版本</button>
            </form>
            <?php if ($versions !== []): ?>
                <table class="table" style="margin-top:1rem;">
                    <?php foreach ($versions as $version): ?>
                        <tr><td>v<?= (int) $version['version'] ?></td><td><?= e($version['title']) ?></td><td class="muted small"><?= e($version['created_at']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canDelete && $archive['status'] === 'active'): ?>
        <form method="post" action="<?= e(url('/archives/' . (int) $archive['id'] . '/delete-request')) ?>" class="form" onsubmit="return confirm('确认提交删除申请？');">
            <?= csrf_field() ?>
            <button class="button button--ghost button--small" type="submit">申请删除</button>
        </form>
    <?php endif; ?>
    <?php if ($canApprove && $archive['status'] === 'delete_requested'): ?>
        <form method="post" action="<?= e(url('/archives/' . (int) $archive['id'] . '/delete-decision')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="family_id" value="<?= (int) $family['id'] ?>">
            <button class="button button--small" name="approve" value="1" type="submit">确认删除（软删除）</button>
            <button class="button button--ghost button--small" name="approve" value="0" type="submit">驳回申请</button>
        </form>
    <?php endif; ?>
</div>
<script src="<?= e(url('assets/js/photo-tags.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/batch-upload.js')) ?>" defer></script>
