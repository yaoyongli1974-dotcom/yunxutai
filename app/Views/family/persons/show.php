<?php
/** @var array<string,mixed> $family */
/** @var array<string,mixed> $person */
/** @var list<array<string,mixed>> $links */
/** @var list<array<string,mixed>> $memorials */
/** @var list<array<string,mixed>> $branches */
/** @var list<mixed> $assigned */
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow">家族人物</p>
    <h1><?= e($person['name']) ?></h1>
    <p class="muted"><?= e(life_dates($person)) ?> · <?= e($person['privacy']) ?> · <?= e($person['verification_status']) ?></p>
    <div class="prose" style="margin:1rem 0;"><?= nl2br(e($person['biography'] ?? '')) ?></div>
    <?php if ($canEdit): ?><p><a class="button button--small button--ghost" href="<?= e(url('/families/' . (int) $family['id'] . '/persons/' . (int) $person['id'] . '/edit')) ?>">编辑资料</a></p><?php endif; ?>

    <section class="card" style="margin-top:1rem;">
        <h2>关联纪念馆</h2>
        <?php foreach ($links as $link): ?><p><a href="<?= e(url('/m/' . $link['memorial_slug'])) ?>"><?= e($link['memorial_title']) ?></a></p><?php endforeach; ?>
        <?php if ($links === []): ?><p class="muted">尚未关联纪念馆。</p><?php endif; ?>
        <?php if ($canEdit): ?>
            <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/persons/' . (int) $person['id'] . '/memorial')) ?>">
                <?= csrf_field() ?>
                <label class="field">选择纪念馆
                    <select name="memorial_id">
                        <?php foreach ($memorials as $memorial): ?><option value="<?= (int) $memorial['id'] ?>"><?= e($memorial['title']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="field">关联说明 <input name="note" maxlength="255"></label>
                <button class="button button--small" type="submit">关联</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($canEdit && $branches !== []): ?>
        <section class="card" style="margin-top:1rem;">
            <h2>所属支系</h2>
            <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/persons/' . (int) $person['id'] . '/branch')) ?>">
                <?= csrf_field() ?>
                <select name="branch_id"><?php foreach ($branches as $branch): ?><option value="<?= (int) $branch['id'] ?>" <?= in_array((int) $branch['id'], array_map('intval', $assigned), true) ? 'selected' : '' ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select>
                <button class="button button--small" type="submit">保存支系</button>
            </form>
        </section>
    <?php endif; ?>
</div>
