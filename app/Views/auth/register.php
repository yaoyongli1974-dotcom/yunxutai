<section class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">开始纪念</p>
        <h1>创建账号</h1>
        <p class="auth-card__lead">一个账号可以创建或参与多座纪念馆。创建一座纪念馆是免费的。</p>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                <p><?= e($errors['form']) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/register')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span>你的称呼（将显示在祭奠与留言旁）</span>
                <input type="text" name="display_name" value="<?= e($old['display_name'] ?? '') ?>" maxlength="40" autocomplete="name" required>
                <?php if (!empty($errors['display_name'])): ?><small class="field__error"><?= e($errors['display_name']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>邮箱（用于登录，不公开）</span>
                <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" maxlength="190" autocomplete="email" required>
                <?php if (!empty($errors['email'])): ?><small class="field__error"><?= e($errors['email']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                <small class="field__hint">至少 10 位，需包含字母和数字。</small>
                <?php if (!empty($errors['password'])): ?><small class="field__error"><?= e($errors['password']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>确认密码</span>
                <input type="password" name="password_confirmation" minlength="10" maxlength="72" autocomplete="new-password" required>
                <?php if (!empty($errors['password_confirmation'])): ?><small class="field__error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>验证：<?= (int) ($captcha['a'] ?? 0) ?> + <?= (int) ($captcha['b'] ?? 0) ?> = ?</span>
                <input type="text" name="captcha" inputmode="numeric" autocomplete="off" required aria-label="验证码">
                <?php if (!empty($errors['captcha'])): ?><small class="field__error"><?= e($errors['captcha']) ?></small><?php endif; ?>
            </label>
            <div>
                <label class="checkbox">
                    <input type="checkbox" name="terms" value="1" <?= !empty($old['terms']) ? 'checked' : '' ?>>
                    <span>我已阅读并同意<a href="<?= e(url('/terms')) ?>" target="_blank" rel="noopener">服务条款</a>与<a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">隐私说明</a></span>
                </label>
                <?php if (!empty($errors['terms'])): ?><small class="field__error"><?= e($errors['terms']) ?></small><?php endif; ?>
            </div>
            <button class="button button--wide" type="submit">创建账号</button>
            <p class="field__hint" style="text-align:center;margin:0;">创建即视为你确认：你将在平台上祭奠的逝者确已离去，并有权以亲属身份整理这些资料。</p>
        </form>

        <p class="auth-card__foot">已有账号？<a href="<?= e(url('/login')) ?>">登录</a></p>
    </div>
</section>
