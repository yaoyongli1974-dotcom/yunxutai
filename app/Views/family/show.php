<?php
/** @var array<string,mixed> $family */
/** @var array<string,int> $counts */
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow">家族空间</p>
    <h1><?= e($family['name']) ?></h1>
    <p class="muted"><?= e($family['description'] ?? '') ?></p>
    <p class="chip">角色：<?= e($role ?? '访客') ?></p>
    <div class="grid grid--4" style="margin:1.5rem 0;">
        <div class="card card--num"><div class="card__num"><?= (int) $counts['members'] ?></div><p class="muted small">家族成员</p></div>
        <div class="card card--num"><div class="card__num"><?= (int) $counts['persons'] ?></div><p class="muted small">人物档案</p></div>
        <div class="card card--num"><div class="card__num"><?= (int) $counts['archives'] ?></div><p class="muted small">数字档案</p></div>
        <div class="card card--num"><div class="card__num"><?= (int) $counts['events'] ?></div><p class="muted small">家族事件</p></div>
    </div>
    <div class="grid grid--3">
        <a class="card" href="<?= e(url('/families/' . (int) $family['id'] . '/persons')) ?>"><h2>人物档案</h2><p class="small">建立人物、补充生平与照片。</p></a>
        <a class="card" href="<?= e(url('/families/' . (int) $family['id'] . '/genealogy')) ?>"><h2>家族谱系</h2><p class="small">提交亲属关系，审核后进入谱系。</p></a>
        <?php if ($canManage): ?>
            <a class="card" href="<?= e(url('/families/' . (int) $family['id'] . '/members')) ?>"><h2>成员管理</h2><p class="small">邀请亲属，分配与管理角色。</p></a>
            <a class="card" href="<?= e(url('/families/' . (int) $family['id'] . '/edit')) ?>"><h2>家族资料</h2><p class="small">修改名称、简介与可见范围。</p></a>
        <?php endif; ?>
    </div>
    <?php if ($pending > 0 && ($canManage ?? false)): ?>
        <div class="notice-card" style="margin-top:1.5rem;"><p>有 <?= (int) $pending ?> 条亲属关系待审核。<a class="text-link" href="<?= e(url('/families/' . (int) $family['id'] . '/genealogy')) ?>">前往审核</a></p></div>
    <?php endif; ?>
</div>
