<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $persons */
/** @var list<string> $errors */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">人物管理</p><h1>合并重复人物</h1>
    <p class="muted">合并只迁移关联关系，被合并人物保留可追溯记录，不直接删除档案或纪念馆关联。</p>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/persons/merge')) ?>" data-confirm="合并后不可撤销，确认继续？">
        <?= csrf_field() ?>
        <label class="field">保留的主人物
            <select name="primary_id"><?php foreach ($persons as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> #<?= (int) $p['id'] ?></option><?php endforeach; ?></select>
        </label>
        <label class="field">被合并人物
            <select name="duplicate_id"><?php foreach ($persons as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> #<?= (int) $p['id'] ?></option><?php endforeach; ?></select>
        </label>
        <button class="button" type="submit">确认合并</button>
    </form>
</div>
