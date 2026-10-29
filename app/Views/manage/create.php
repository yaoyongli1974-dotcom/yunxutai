<?php
$value = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default);
$declarationChecked = !empty($old['declaration']);
?>

<section class="shell manage-create">
    <p class="eyebrow">新的纪念馆</p>
    <h1>为亲人创建纪念馆</h1>
    <p class="muted">
        纪念馆创建后先以草稿形式保存，资料补充完整后再发布。逝者的姓名与生平请如实填写。
    </p>

    <?php if (!empty($errors['form'])): ?>
        <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form class="form" method="post" action="<?= e(url('/manage/memorials')) ?>">
        <?= csrf_field() ?>

        <div class="manage-create__declaration">
            <h2 style="font-size:1.05rem;margin-bottom:0.3rem;">创建声明</h2>
            <p class="muted" style="font-size:0.84rem;margin-bottom:0.4rem;">请在创建前逐条阅读并确认：</p>
            <ol>
                <?php foreach ($declarations as $declaration): ?>
                    <li><?= e($declaration) ?></li>
                <?php endforeach; ?>
            </ol>
            <label class="checkbox" style="margin-top:0.8rem;">
                <input type="checkbox" name="declaration" value="1"<?= $declarationChecked ? ' checked' : '' ?>>
                <span>我已阅读并同意以上声明，确认所填写的信息真实、准确。</span>
            </label>
            <?php if (!empty($errors['declaration'])): ?>
                <small class="field__error"><?= e($errors['declaration']) ?></small>
            <?php endif; ?>
        </div>

        <div class="manage-create__grid">
            <label class="field">
                <span>逝者姓名</span>
                <input type="text" name="full_name" value="<?= e($value('full_name')) ?>" maxlength="120" required>
                <?php if (!empty($errors['full_name'])): ?><small class="field__error"><?= e($errors['full_name']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>性别</span>
                <?php $gender = $value('gender', 'unknown'); ?>
                <select name="gender">
                    <option value="unknown"<?= $gender === 'unknown' ? ' selected' : '' ?>>未注明</option>
                    <option value="male"<?= $gender === 'male' ? ' selected' : '' ?>>男</option>
                    <option value="female"<?= $gender === 'female' ? ' selected' : '' ?>>女</option>
                </select>
                <?php if (!empty($errors['gender'])): ?><small class="field__error"><?= e($errors['gender']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>出生日期</span>
                <input type="date" name="birth_date" value="<?= e($value('birth_date')) ?>">
                <?php if (!empty($errors['birth_date'])): ?><small class="field__error"><?= e($errors['birth_date']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>逝世日期</span>
                <input type="date" name="death_date" value="<?= e($value('death_date')) ?>">
                <?php if (!empty($errors['death_date'])): ?><small class="field__error"><?= e($errors['death_date']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>出生地</span>
                <input type="text" name="birth_place" value="<?= e($value('birth_place')) ?>" maxlength="190">
                <?php if (!empty($errors['birth_place'])): ?><small class="field__error"><?= e($errors['birth_place']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>安葬地</span>
                <input type="text" name="death_place" value="<?= e($value('death_place')) ?>" maxlength="190">
                <?php if (!empty($errors['death_place'])): ?><small class="field__error"><?= e($errors['death_place']) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>生平简介</span>
                <textarea name="bio" maxlength="5000" placeholder="可以写下去过的城市、做过的工作、喜欢的事物，以及家人想记住的细节。"><?= e($value('bio')) ?></textarea>
                <small class="field__hint">5000 字以内。之后也可以继续补充。</small>
                <?php if (!empty($errors['bio'])): ?><small class="field__error"><?= e($errors['bio']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>纪念馆名称（可选）</span>
                <input type="text" name="title" value="<?= e($value('title')) ?>" maxlength="190" placeholder="例如：纪念张明远">
                <small class="field__hint">不填写时自动使用“纪念 + 姓名”。</small>
                <?php if (!empty($errors['title'])): ?><small class="field__error"><?= e($errors['title']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>纪念馆链接（可选）</span>
                <input type="text" name="slug" value="<?= e($value('slug')) ?>" maxlength="60" placeholder="例如：zhang-mingyuan">
                <small class="field__hint">公开地址形如 /m/zhang-mingyuan。只能使用小写字母、数字和连字符。</small>
                <?php if (!empty($errors['slug'])): ?><small class="field__error"><?= e($errors['slug']) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>纪念页引言（可选）</span>
                <textarea name="summary" maxlength="500" style="min-height:90px;" placeholder="用一两句话概括你希望访客先读到的话。"><?= e($value('summary')) ?></textarea>
                <?php if (!empty($errors['summary'])): ?><small class="field__error"><?= e($errors['summary']) ?></small><?php endif; ?>
            </label>
            <div class="field field--wide">
                <span>访问范围</span>
                <input type="hidden" name="visibility" value="private">
                <div class="manage-option manage-option--active" style="margin-top:0.35rem;">
                    <strong>私密馆（创建时固定）</strong>
                    新馆仅馆主、协作者与受邀亲友可见。请先在“身份核验”提交死亡证明或亲属关系材料，平台核验通过后，才可在设置中选择公开或不公开收录。
                </div>
                <?php if (!empty($errors['visibility'])): ?><small class="field__error"><?= e($errors['visibility']) ?></small><?php endif; ?>
            </div>
        </div>

        <div class="manage-actions-row">
            <button class="button" type="submit">创建纪念馆</button>
            <a class="button button--ghost" href="<?= e(url('/dashboard')) ?>">返回</a>
        </div>
    </form>
</section>
