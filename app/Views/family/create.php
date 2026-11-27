<?php
/** @var array<string,mixed> $family */
/** @var list<string> $errors */
/** @var array<string,mixed> $old */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">家族空间</p><h1>创建家族</h1>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <form class="form form--quiet" method="post" action="<?= e(url('/families')) ?>">
        <?= csrf_field() ?>
        <label class="field">家族名称 <input name="name" required maxlength="190" value="<?= e($old['name'] ?? '') ?>"></label>
        <label class="field">标识 slug（小写字母、数字、连字符；留空自动生成） <input name="slug" value="<?= e($old['slug'] ?? '') ?>"></label>
        <label class="field">姓氏 <input name="surname" maxlength="50" value="<?= e($old['surname'] ?? '') ?>"></label>
        <label class="field">原籍 <input name="origin_place" maxlength="190" value="<?= e($old['origin_place'] ?? '') ?>"></label>
        <label class="field">简介 <textarea name="description" rows="4"><?= e($old['description'] ?? '') ?></textarea></label>
        <label class="field">可见范围
            <select name="privacy">
                <?php foreach (['private' => '仅成员可见', 'unlisted' => '持链接可见（需授权）', 'public' => '公开'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($old['privacy'] ?? 'private') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form__actions"><button class="button" type="submit">创建家族</button></div>
    </form>
</div>
