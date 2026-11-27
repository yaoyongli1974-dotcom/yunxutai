<?php
/** @var list<array<string,mixed>> $families */
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">家族空间</p><h1>我的家族</h1></div>
        <a class="button button--small" href="<?= e(url('/families/new')) ?>">创建家族</a>
    </div>
    <?php if ($families === []): ?>
        <div class="empty"><p>你还没有加入任何家族。创建家族后，可以邀请亲属共同整理人物、档案与记忆。</p></div>
    <?php else: ?>
        <div class="grid grid--3">
            <?php foreach ($families as $family): ?>
                <a class="card" href="<?= e(url('/families/' . (int) $family['id'])) ?>">
                    <h2 style="margin-top:0;"><?= e($family['name']) ?></h2>
                    <p class="muted small">角色：<?= e($family['member_role']) ?> · <?= e($family['privacy']) ?></p>
                    <p class="small"><?= e(mb_substr((string) ($family['description'] ?? ''), 0, 90)) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
