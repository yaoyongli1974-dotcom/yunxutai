<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $persons */
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">家族人物</p><h1><?= e($family['name']) ?> · 人物档案</h1></div>
        <?php if ($canEdit): ?><a class="button button--small" href="<?= e(url('/families/' . (int) $family['id'] . '/persons/new')) ?>">新增人物</a><?php endif; ?>
    </div>
    <?php if ($persons === []): ?><div class="empty"><p>还没有人物档案。人物与登录账号分离，可以为已故亲属建立资料。</p></div><?php endif; ?>
    <div class="grid grid--3">
        <?php foreach ($persons as $person): ?>
            <a class="card" href="<?= e(url('/families/' . (int) $family['id'] . '/persons/' . (int) $person['id'])) ?>">
                <h2 style="margin-top:0;"><?= e($person['name']) ?></h2>
                <p class="muted small"><?= e(format_date($person['birth_date'] ?? null)) ?> — <?= e(format_date($person['death_date'] ?? null)) ?></p>
                <p class="small"><?= e(mb_substr((string) ($person['biography'] ?? ''), 0, 80)) ?></p>
                <span class="chip"><?= e($person['privacy']) ?></span>
                <span class="chip"><?= e($person['verification_status']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($duplicates !== []): ?>
        <div class="notice-card" style="margin-top:1.5rem;">
            <strong>疑似重复人物</strong>
            <?php foreach ($duplicates as $dup): ?><p class="small"><?= e($dup['name']) ?>（<?= e($dup['ids']) ?>）</p><?php endforeach; ?>
            <a class="text-link" href="<?= e(url('/families/' . (int) $family['id'] . '/persons/merge')) ?>">前往人工核对与合并</a>
        </div>
    <?php endif; ?>
</div>
