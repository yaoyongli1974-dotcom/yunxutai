<?php
use App\Core\Auth;
use App\Core\Policy;
/** @var array<string,mixed> $family */
/** @var array<string,mixed> $exhibition */
/** @var list<array<string,mixed>> $items */
/** @var bool $canEdit */
/** @var bool $canPublish */
/** @var list<array<string,mixed>> $archives */
$familyId=(int)$family['id'];
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">数字展馆</p><h1><?= e($exhibition['title']) ?></h1></div>
        <a class="button button--ghost button--small" href="<?= e(url('/families/'.$familyId.'/exhibitions')) ?>">返回列表</a>
    </div>
    <p class="muted"><?= e($exhibition['description'] ?? '') ?></p>
    <p class="chip"><?= e($exhibition['status']) ?> · <?= e($exhibition['privacy']) ?></p>

    <?php if ($canPublish): ?>
        <?php if ($exhibition['status'] !== 'published'): ?>
        <form method="post" action="<?= e(url('/exhibitions/'.(int)$exhibition['id'].'/publish')) ?>">
            <?= csrf_field() ?><button class="button button--small" type="submit">发布展馆</button>
        </form>
        <?php else: ?>
        <form method="post" action="<?= e(url('/exhibitions/'.(int)$exhibition['id'].'/offline')) ?>">
            <?= csrf_field() ?><button class="button button--ghost button--small" type="submit">下线展馆</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <section class="section section--tight">
        <h2>添加档案内容</h2>
        <form class="form" method="post" action="<?= e(url('/exhibitions/'.(int)$exhibition['id'].'/items')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="item_type" value="archive">
            <div class="field"><label>档案</label><select name="item_id">
                <?php foreach ($archives as $archive): ?><option value="<?= (int)$archive['id'] ?>"><?= e($archive['title']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="field"><label>栏目</label><input name="section" value="default"></div>
            <button class="button button--small" type="submit">加入展馆</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="section section--tight">
        <h2>展馆内容</h2>
        <?php if ($items === []): ?><div class="empty"><p>尚未添加内容。</p></div><?php endif; ?>
        <?php foreach ($items as $item): ?>
        <div class="card" style="margin-bottom:.5rem;">
            <p><?= e($item['label']) ?></p>
            <?php if ($canEdit): ?>
            <p class="small muted">栏目：<?= e($item['section']) ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
</div>
