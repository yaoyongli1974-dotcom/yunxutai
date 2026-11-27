<?php
/** @var array<string,mixed> $family */
/** @var array<string,mixed> $person */
/** @var list<string> $errors */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">家族人物</p><h1>编辑 <?= e($person['name']) ?></h1>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/persons/' . (int) $person['id'] . '/edit')) ?>">
        <?= csrf_field() ?>
        <label class="field">姓名 <input name="name" required value="<?= e($person['name']) ?>"></label>
        <label class="field">出生日期 <input type="date" name="birth_date" value="<?= e($person['birth_date'] ?? '') ?>"></label>
        <label class="field">逝世日期 <input type="date" name="death_date" value="<?= e($person['death_date'] ?? '') ?>"></label>
        <label class="field"><input type="checkbox" name="is_deceased" value="1" <?= !empty($person['is_deceased']) ? 'checked' : '' ?>> 已故</label>
        <label class="field">生平 <textarea name="biography" rows="5"><?= e($person['biography'] ?? '') ?></textarea></label>
        <label class="field">隐私
            <select name="privacy">
                <?php foreach (['private' => '仅成员可见', 'family' => '家族成员可见', 'public' => '公开（需核验）'] as $v => $l): ?>
                    <option value="<?= e($v) ?>" <?= ($person['privacy'] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form__actions"><button class="button" type="submit">保存</button></div>
    </form>
</div>
