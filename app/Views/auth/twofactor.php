<section class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">二次验证</p>
        <h1>输入动态验证码</h1>
        <p class="auth-card__lead">密码已通过。请打开身份验证器 App，输入「静思园」当前的 6 位动态码。</p>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                <p><?= e($errors['form']) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/2fa')) ?>" class="form">
            <?= csrf_field() ?>
            <label class="field">
                <span>动态验证码</span>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
                <?php if (!empty($errors['code'])): ?><small class="field__error"><?= e($errors['code']) ?></small><?php endif; ?>
            </label>
            <button class="button button--wide" type="submit">验证并登录</button>
            <p class="field__hint" style="text-align:center;margin:0;">动态码每 30 秒更新一次。若连续输入错误，需要稍后再试。</p>
        </form>

        <p class="auth-card__foot"><a href="<?= e(url('/login')) ?>">返回登录</a></p>
    </div>
    <p class="auth-side">此步骤用于确认登录者持有你的身份验证器。手机丢失时，请联系平台管理员重置二次验证。</p>
</section>
