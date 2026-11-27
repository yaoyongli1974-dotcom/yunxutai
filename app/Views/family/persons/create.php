<?php
/** @var array<string,mixed> $family */
/** @var list<string> $errors */
/** @var array<string,mixed> $old */
?>
<div class="shell" style="padding:2.5rem 0;max-width:760px;">
    <p class="eyebrow">家族人物</p><h1>新增人物</h1>
    <p class="muted small">人物资料默认私密；活人资料不可公开，且新建人物不会自动成为已确认事实。</p>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/persons')) ?>">
        <?= csrf_field() ?>
        <label class="field">姓名 <input name="name" required maxlength="190" value="<?= e($old['name'] ?? '') ?>"></label>
        <label class="field">性别
            <select name="gender">
                <?php foreach (['unknown' => '未知', 'male' => '男', 'female' => '女', 'other' => '其他'] as $v => $l): ?>
                    <option value="<?= e($v) ?>" <?= ($old['gender'] ?? 'unknown') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">出生日期 <input type="date" name="birth_date" value="<?= e($old['birth_date'] ?? '') ?>"></label>
        <label class="field">逝世日期 <input type="date" name="death_date" value="<?= e($old['death_date'] ?? '') ?>"></label>
        <label class="field"><input type="checkbox" name="is_deceased" value="1" <?= !empty($old['is_deceased']) ? 'checked' : '' ?>> 已故</label>
        <label class="field">出生地 <input name="birth_place" value="<?= e($old['birth_place'] ?? '') ?>"></label>
        <label class="field">生平 <textarea name="biography" rows="5"><?= e($old['biography'] ?? '') ?></textarea></label>
        <label class="field">隐私
            <select name="privacy">
                <?php foreach (['private' => '仅成员可见', 'family' => '家族成员可见', 'public' => '公开（需核验）'] as $v => $l): ?>
                    <option value="<?= e($v) ?>" <?= ($old['privacy'] ?? 'private') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form__actions"><button class="button" type="submit">保存人物</button></div>
    </form>
</div>
