<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $archives */
/** @var string $search */
/** @var bool $canUpload */
/** @var bool $canApprove */
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">数字档案馆</p><h1><?= e($family['name']) ?> · 档案</h1></div>
        <?php if ($canUpload): ?><a class="button button--small" href="<?= e(url('/families/' . (int) $family['id'] . '/archives/new')) ?>">新建档案</a><?php endif; ?>
    </div>

    <form class="form" method="get" action="<?= e(url('/families/' . (int) $family['id'] . '/archives')) ?>" style="margin-bottom:1.5rem;">
        <div class="field">
            <label for="q">搜索档案</label>
            <input id="q" name="q" value="<?= e($search) ?>" placeholder="标题或描述关键词">
        </div>
        <button class="button button--ghost button--small" type="submit">搜索</button>
    </form>

    <?php if ($archives === []): ?><div class="empty"><p>还没有可查看的档案。档案由家族成员整理，私密资料仅对授权成员可见。</p></div><?php endif; ?>
    <div class="grid grid--3">
        <?php foreach ($archives as $archive): ?>
            <a class="card" href="<?= e(url('/archives/' . (int) $archive['id'])) ?>">
                <h2 style="margin-top:0;"><?= e($archive['title']) ?></h2>
                <p class="muted small"><?= e(format_date($archive['event_date'] ?? null)) ?> · <?= e($archive['archive_type']) ?></p>
                <p class="small"><?= e(mb_substr((string) ($archive['description'] ?? ''), 0, 80)) ?></p>
                <?php if ($archive['status'] === 'delete_requested'): ?><span class="chip">待删除审核</span><?php endif; ?>
                <span class="chip"><?= e($archive['privacy']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
