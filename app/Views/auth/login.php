<section class="auth-shell">
    <div class="auth-card">
        <p class="eyebrow">欢迎回来</p>
        <h1>登录</h1>
        <p class="auth-card__lead">登录后可以管理纪念馆、祭奠与留言。</p>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                <p><?= e($errors['form']) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/login')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
            <label class="field">
                <span>邮箱</span>
                <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required>
                <?php if (!empty($errors['email'])): ?><small class="field__error"><?= e($errors['email']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" autocomplete="current-password" required>
                <?php if (!empty($errors['password'])): ?><small class="field__error"><?= e($errors['password']) ?></small><?php endif; ?>
            </label>
            <button class="button button--wide" type="submit">登录</button>
            <p class="field__hint" style="text-align:center;margin:0;">连续出错会暂停几分钟，请留意邮箱大小写。</p>
        </form>

        <p class="auth-card__foot">还没有账号？<a href="<?= e(url('/register')) ?>">创建一个</a></p>
    </div>
    <p class="auth-side">登录会话由 Cookie 维持，详见<a href="<?= e(url('/privacy')) ?>">隐私与数据保护</a>。</p>
</section>
