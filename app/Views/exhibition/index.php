<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $exhibitions */
/** @var bool $canEdit */
$familyId=(int)$family['id'];
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">数字展馆</p><h1><?= e($family['name']) ?> · 展馆</h1></div>
        <a class="button button--ghost button--small" href="<?= e(url('/families/'.$familyId)) ?>">返回家族</a>
    </div>
    <?php if ($canEdit): ?>
    <section class="section section--tight">
        <h2>创建展馆</h2>
        <form class="form" method="post" action="<?= e(url('/families/'.$familyId.'/exhibitions')) ?>">
            <?= csrf_field() ?>
            <div class="field"><label>标题</label><input name="title" required></div>
            <div class="field"><label>主题</label><input name="theme"></div>
            <div class="field"><label>简介</label><textarea name="description" rows="3"></textarea></div>
            <div class="field"><label>隐私</label><select name="privacy"><option value="family">家族可见</option><option value="private">仅自己</option><option value="public">公开</option></select></div>
            <button class="button" type="submit">创建草稿</button>
        </form>
    </section>
    <?php endif; ?>
    <?php if ($exhibitions === []): ?><div class="empty"><p>暂无可见展馆。</p></div><?php endif; ?>
    <div class="grid grid--3">
    <?php foreach ($exhibitions as $exhibition): ?>
        <a class="card" href="<?= e(url('/exhibitions/'.(int)$exhibition['id'])) ?>">
            <h2 style="margin-top:0"><?= e($exhibition['title']) ?></h2>
            <p class="small muted"><?= e($exhibition['status']) ?> · <?= e($exhibition['privacy']) ?></p>
        </a>
    <?php endforeach; ?>
    </div>
</div>
