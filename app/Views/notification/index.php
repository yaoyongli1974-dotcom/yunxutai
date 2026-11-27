<?php
/** @var list<array<string,mixed>> $notifications */
/** @var array<string,array{in_app:bool,email:bool}> $preferences */
/** @var list<string> $types */
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">统一通知中心</p><h1>通知</h1></div>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?><button class="button button--ghost button--small" type="submit">全部标为已读</button>
        </form>
    </div>
    <?php if ($notifications === []): ?><div class="empty"><p>暂无通知。</p></div><?php endif; ?>
    <?php foreach ($notifications as $notification): ?>
        <div class="card" style="margin-bottom:.5rem;">
            <p><strong><?= e($notification['title']) ?></strong> <span class="chip"><?= e($notification['type']) ?></span></p>
            <p class="small muted"><?= e($notification['created_at']) ?> · <?= $notification['read_at'] ? '已读' : '未读' ?></p>
            <?php if (!empty($notification['body'])): ?><p><?= e($notification['body']) ?></p><?php endif; ?>
            <?php if ($notification['read_at'] === null): ?>
            <form method="post" action="<?= e(url('/notifications/'.(int)$notification['id'].'/read')) ?>">
                <?= csrf_field() ?><button class="button button--small" type="submit">标为已读</button>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <section class="section section--tight">
        <h2>通知偏好</h2>
        <table class="table">
            <?php foreach ($types as $type): ?>
                <?php $pref = $preferences[$type] ?? ['in_app' => true, 'email' => false]; ?>
                <tr>
                    <td><?= e($type) ?></td>
                    <td>
                        <form method="post" action="<?= e(url('/notifications/preferences')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <label><input type="checkbox" name="in_app" value="1" <?= $pref['in_app'] ? 'checked' : '' ?>> 站内</label>
                            <label><input type="checkbox" name="email" value="1" <?= $pref['email'] ? 'checked' : '' ?>> 邮件</label>
                            <button class="button button--small" type="submit">保存</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>
