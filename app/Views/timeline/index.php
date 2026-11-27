<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $events */
/** @var array<int,list<array<string,mixed>>> $withPersons */
/** @var bool $canEdit */
$familyId=(int)$family['id'];
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">家族时间轴</p><h1><?= e($family['name']) ?> · 家族事件</h1></div>
        <a class="button button--ghost button--small" href="<?= e(url('/families/'.$familyId)) ?>">返回家族</a>
    </div>
    <?php if ($canEdit): ?>
    <section class="section section--tight">
        <h2>添加家族事件</h2>
        <form class="form" method="post" action="<?= e(url('/families/'.$familyId.'/timeline')) ?>">
            <?= csrf_field() ?>
            <div class="field"><label>标题</label><input name="title" required></div>
            <div class="field"><label>日期</label><input name="event_date" type="date" required></div>
            <div class="field"><label>地点</label><input name="location"></div>
            <div class="field"><label>类型</label><input name="event_type" value="other"></div>
            <div class="field"><label>说明</label><textarea name="description" rows="3"></textarea></div>
            <div class="field"><label>隐私</label><select name="privacy"><option value="family">家族可见</option><option value="private">仅自己</option><option value="public">公开</option></select></div>
            <div class="field"><label>来源</label><select name="source_type"><option value="user_input">家属提交</option><option value="archive">档案提取</option><option value="oral_history">采访转写</option><option value="person_profile">人物资料</option></select></div>
            <button class="button" type="submit">保存事件</button>
        </form>
    </section>
    <?php endif; ?>
    <section class="section section--tight">
        <?php if ($events === []): ?><div class="empty"><p>暂无可见事件。</p></div><?php endif; ?>
        <?php foreach ($events as $event): ?>
        <div class="card" style="margin-bottom:.75rem;">
            <p class="eyebrow"><?= e($event['event_date']) ?> · <?= e($event['event_type']) ?></p>
            <h2><?= e($event['title']) ?></h2>
            <p class="small"><?= e($event['location'] ?? '') ?> · 来源：<?= e($event['source_type']) ?></p>
            <p><?= e(mb_substr((string)($event['description'] ?? ''),0,500)) ?></p>
            <?php if (($withPersons[$event['id']] ?? []) !== []): ?>
            <p class="small muted">关联人物：<?php foreach ($withPersons[$event['id']] as $person) { echo e($person['name']).' '; } ?></p>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/family-events/'.(int)$event['id'].'/archive')) ?>">
                <?= csrf_field() ?><button class="button button--ghost button--small" type="submit">归档</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
</div>
