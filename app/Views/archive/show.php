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
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow"><a class="text-link" href="<?= e(url('/families/' . (int) $family['id'] . '/archives')) ?>">数字档案馆</a></p>
    <div class="section-heading section-heading--row">
        <div>
            <h1><?= e($archive['title']) ?></h1>
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
            <?php endforeach; ?>
        </table>
        <?php if ($canUpload): ?>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('/archives/' . (int) $archive['id'] . '/files')) ?>" class="form" style="margin-top:1rem;">
                <?= csrf_field() ?>
                <div class="field"><label for="file">上传原始文件（不会覆盖已有文件）</label><input id="file" name="file" type="file" required></div>
                <button class="button button--small" type="submit">上传并归档</button>
            </form>
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
