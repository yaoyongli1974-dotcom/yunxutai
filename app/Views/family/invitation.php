<?php
/** @var array<string,mixed> $invitation */
?>
<div class="shell" style="padding:2.5rem 0;max-width:680px;">
    <p class="eyebrow">家族邀请</p>
    <h1>加入「<?= e($invitation['family_name']) ?>」</h1>
    <p>受邀邮箱：<?= e($invitation['email']) ?> · 角色：<?= e($invitation['role']) ?></p>
    <p class="muted small">有效期至 <?= e($invitation['expires_at']) ?>。邀请只能由受邀邮箱本人接受；撤销或过期后不可再用。</p>
    <?php if (!$emailMatches): ?>
        <div class="banner banner--veil"><p>当前登录邮箱与受邀邮箱不一致，无法接受此邀请。</p></div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/invitations/' . $token)) ?>">
            <?= csrf_field() ?>
            <button class="button" type="submit">接受邀请，加入家族</button>
        </form>
    <?php endif; ?>
</div>
