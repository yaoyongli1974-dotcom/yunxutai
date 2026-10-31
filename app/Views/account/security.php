<?php
/**
 * 二次验证（TOTP）设置。
 *
 * 启用前只展示会话中的待确认密钥，不读取或回显数据库中的已启用密钥。
 * 当前版本不生成二维码图片；如后续接入本地 QR 库，可在这里把 $otpauthUri 渲染为图片，
 * 不必改动服务端流程。
 */
?>
<section style="max-width:34rem;">
    <?php if (!empty($errors['form'])): ?>
        <div class="form-error" role="alert" style="margin-bottom:1rem;"><p><?= e($errors['form']) ?></p></div>
    <?php endif; ?>

    <?php if ($totpEnabled): ?>
        <h2 style="font-size:1.2rem;">二次验证已启用</h2>
        <p class="small muted">登录时除了密码，还需要输入身份验证器中的 6 位动态码。动态码每 30 秒更新一次。</p>

        <div class="notice" style="margin:1rem 0;padding:0.9rem 1rem;border:1px solid var(--line);border-radius:var(--radius);">
            <p style="margin:0;"><strong>当前状态：</strong>已启用</p>
            <p class="small muted" style="margin:0.35rem 0 0;">手机丢失或更换设备时，请联系平台管理员重置二次验证；重置后可用密码重新登录并重新绑定。</p>
        </div>

        <form class="form" method="post" action="<?= e(url('/account/totp/disable')) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>输入当前动态码以停用</span>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                <?php if (!empty($errors['code'])): ?><small class="field__error"><?= e($errors['code']) ?></small><?php endif; ?>
                <small class="field__hint">停用后将只凭密码登录。确认手机仍在身边时再操作。</small>
            </label>
            <div class="form__actions">
                <button class="button button--danger" type="submit">停用二次验证</button>
            </div>
        </form>

    <?php else: ?>
        <h2 style="font-size:1.2rem;">启用二次验证</h2>
        <p class="small muted">启用后，登录时除密码外还需输入身份验证器中的 6 位动态码。建议管理员账号开启。</p>

        <div style="margin:1.2rem 0;">
            <p style="margin:0 0 0.45rem;">1. 打开身份验证器 App（如 Google Authenticator、Microsoft Authenticator、1Password），选择「手动输入密钥」。</p>
            <p class="small muted" style="margin:0 0 0.45rem;">账号：云叙台　类型：基于时间（TOTP）　位数：6　周期：30 秒</p>
            <p style="margin:0 0 0.35rem;">密钥：</p>
            <code style="display:block;padding:0.7rem 0.85rem;border:1px solid var(--line);border-radius:var(--radius);background:var(--paper-deep);word-break:break-all;font-size:0.95rem;"><?= e((string) $pendingSecret) ?></code>
            <p class="small muted" style="margin:0.75rem 0 0.35rem;">也可以复制下面的 otpauth 地址，在支持 URI 的验证器中导入：</p>
            <code style="display:block;padding:0.7rem 0.85rem;border:1px solid var(--line);border-radius:var(--radius);background:var(--paper-deep);word-break:break-all;font-size:0.78rem;line-height:1.5;"><?= e((string) $otpauthUri) ?></code>
            <p class="small muted" style="margin:0.75rem 0 0;">当前版本不生成二维码图片。密钥只在本页显示一次，请先完成绑定再离开。</p>
        </div>

        <form class="form" method="post" action="<?= e(url('/account/totp/activate')) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>2. 输入身份验证器显示的 6 位动态码</span>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                <?php if (!empty($errors['code'])): ?><small class="field__error"><?= e($errors['code']) ?></small><?php endif; ?>
            </label>
            <div class="form__actions">
                <button class="button" type="submit">确认启用</button>
                <span class="form__foot">启用后请保留身份验证器数据；手机丢失时需由管理员重置。</span>
            </div>
        </form>
    <?php endif; ?>
</section>
