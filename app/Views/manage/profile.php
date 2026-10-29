<?php
use App\Core\View;

$value = static fn (string $key, string $default = ''): string => (string) ($old[$key] ?? $default);
$base = '/manage/memorials/' . (int) $memorial['id'];
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'profile']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">纪念馆资料</p>
                <h1>逝者信息与纪念页</h1>
                <p class="muted" style="margin:0;">修改会立即反映到公开页面。请以家属确认过的事实为准。</p>
            </div>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <form class="form manage-form" method="post" action="<?= e(url($base . '/profile')) ?>">
                <?= csrf_field() ?>

                <div class="manage-form--grid">
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
                </div>

                <label class="field">
                    <span>生平简介</span>
                    <textarea name="bio" maxlength="5000"><?= e($value('bio')) ?></textarea>
                    <small class="field__hint">5000 字以内。可记录生平经历、性格与家人想记住的细节。</small>
                    <?php if (!empty($errors['bio'])): ?><small class="field__error"><?= e($errors['bio']) ?></small><?php endif; ?>
                </label>

                <label class="field">
                    <span>纪念馆名称</span>
                    <input type="text" name="title" value="<?= e($value('title')) ?>" maxlength="190">
                    <small class="field__hint">留空时自动使用“纪念 + 姓名”。</small>
                    <?php if (!empty($errors['title'])): ?><small class="field__error"><?= e($errors['title']) ?></small><?php endif; ?>
                </label>

                <label class="field">
                    <span>纪念页引言</span>
                    <textarea name="summary" maxlength="500" style="min-height:90px;"><?= e($value('summary')) ?></textarea>
                    <small class="field__hint">500 字以内，显示在纪念馆页面开头。</small>
                    <?php if (!empty($errors['summary'])): ?><small class="field__error"><?= e($errors['summary']) ?></small><?php endif; ?>
                </label>

                <label class="field">
                    <span>纪念馆链接</span>
                    <input type="text" name="slug" value="<?= e($value('slug')) ?>" maxlength="60">
                    <small class="field__hint">公开地址：<?= e(url('/m/')) ?><?= e($value('slug', (string) $memorial['slug'])) ?>。修改后旧链接将不再可用。</small>
                    <?php if (!empty($errors['slug'])): ?><small class="field__error"><?= e($errors['slug']) ?></small><?php endif; ?>
                </label>

                <div class="field">
                    <span>访问范围</span>
                    <input type="hidden" name="visibility" value="<?= e((string) $memorial['visibility']) ?>">
                    <p class="muted">当前为「<?= e(visibility_label((string) $memorial['visibility'])) ?>」。访问范围与搜索收录请在 <a class="text-link" href="<?= e(url($base . '/settings')) ?>">访问与留言</a> 中修改；未完成身份核验前只能保持私密。</p>
                    <?php if (!empty($errors['visibility'])): ?><small class="field__error"><?= e($errors['visibility']) ?></small><?php endif; ?>
                </div>

                <div class="manage-actions-row">
                    <button class="button" type="submit">保存资料</button>
                    <a class="button button--ghost" href="<?= e(url('/m/' . $memorial['slug'])) ?>">查看公开页面</a>
                    <a class="text-link" href="<?= e(url($base)) ?>">返回概况</a>
                </div>
            </form>
        </section>
    </div>
</div>
