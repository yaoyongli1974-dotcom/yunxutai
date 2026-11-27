<?php
/** @var array<string,mixed> $family */
/** @var list<string> $errors */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">家族空间</p><h1>编辑家族资料</h1>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/edit')) ?>">
        <?= csrf_field() ?>
        <label class="field">家族名称 <input name="name" required value="<?= e($family['name']) ?>"></label>
        <label class="field">姓氏 <input name="surname" value="<?= e($family['surname'] ?? '') ?>"></label>
        <label class="field">原籍 <input name="origin_place" value="<?= e($family['origin_place'] ?? '') ?>"></label>
        <label class="field">简介 <textarea name="description" rows="4"><?= e($family['description'] ?? '') ?></textarea></label>
        <label class="field">可见范围
            <select name="privacy">
                <?php foreach (['private' => '仅成员可见', 'unlisted' => '持链接可见（需授权）', 'public' => '公开'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($family['privacy'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form__actions"><button class="button" type="submit">保存资料</button></div>
    </form>
</div>
