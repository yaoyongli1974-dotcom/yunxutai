<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$currentUserId = (int) (auth_user()['id'] ?? 0);
$inviteEmail = (string) ($old['email'] ?? '');
$inviteRole = (string) ($old['role'] ?? 'viewer');
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'members']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">成员与邀请</p>
                <h1>让家人一起维护</h1>
                <p class="muted" style="margin:0;">协作者可以补充内容与审核留言；亲友只能查看，不能修改。</p>
            </div>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>邀请新成员</h2>
            <p class="manage-note" style="margin-top:-0.4rem;">
                邀请链接 7 天内有效。生成后请直接复制给受邀的家人，平台暂不代为发送邮件。
            </p>
            <form class="form manage-form manage-form--grid" method="post" action="<?= e(url($base . '/members/invite')) ?>">
                <?= csrf_field() ?>
                <label class="field">
                    <span>受邀邮箱（可选）</span>
                    <input type="email" name="email" value="<?= e($inviteEmail) ?>" maxlength="190" placeholder="用于备注，可留空">
                    <small class="field__hint">填写时仅作记录与提示，链接仍可被任何持有者使用。</small>
                    <?php if (!empty($errors['email'])): ?><small class="field__error"><?= e($errors['email']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>角色</span>
                    <select name="role">
                        <option value="editor"<?= $inviteRole === 'editor' ? ' selected' : '' ?>>协作者 —— 可编辑内容、审核留言</option>
                        <option value="viewer"<?= $inviteRole === 'viewer' ? ' selected' : '' ?>>亲友 —— 仅可查看</option>
                    </select>
                    <?php if (!empty($errors['role'])): ?><small class="field__error"><?= e($errors['role']) ?></small><?php endif; ?>
                </label>
                <div class="field--wide">
                    <button class="button" type="submit">生成邀请链接</button>
                </div>
            </form>
        </section>

        <section class="manage-section">
            <h2>当前成员</h2>
            <table class="manage-table">
                <thead>
                <tr>
                    <th>成员</th>
                    <th>角色</th>
                    <th>最近登录</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $member): ?>
                    <?php
                    $isSelf = (int) $member['user_id'] === $currentUserId;
                    $roleValue = ($old['member_id'] ?? 0) === (int) $member['id'] && !empty($old['role'])
                        ? (string) $old['role']
                        : (string) $member['role'];
                    ?>
                    <tr>
                        <td>
                            <?= e($member['display_name']) ?>
                            <?php if ($isSelf): ?><span class="manage-pill">你</span><?php endif; ?>
                            <div class="manage-note"><?= e($member['email']) ?></div>
                        </td>
                        <td>
                            <?php if ($member['role'] === 'owner'): ?>
                                <span class="manage-pill">馆主</span>
                            <?php else: ?>
                                <form class="manage-table__actions" method="post" action="<?= e(url($base . '/members/' . (int) $member['id'] . '/role')) ?>">
                                    <?= csrf_field() ?>
                                    <select name="role" aria-label="修改 <?= e($member['display_name']) ?> 的角色">
                                        <option value="editor"<?= $roleValue === 'editor' ? ' selected' : '' ?>>协作者</option>
                                        <option value="viewer"<?= $roleValue === 'viewer' ? ' selected' : '' ?>>亲友</option>
                                    </select>
                                    <button class="button button--small button--ghost" type="submit">保存</button>
                                </form>
                                <?php if (($old['member_id'] ?? 0) === (int) $member['id'] && !empty($errors['role'])): ?>
                                    <small class="field__error"><?= e($errors['role']) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e($member['last_login_at'] ? format_datetime($member['last_login_at']) : '尚未登录') ?></td>
                        <td>
                            <?php if ($member['role'] !== 'owner' && !$isSelf): ?>
                                <form method="post" action="<?= e(url($base . '/members/' . (int) $member['id'] . '/remove')) ?>" data-confirm="确认移除 <?= e($member['display_name']) ?>？移除后对方将无法再访问纪念馆管理。">
                                    <?= csrf_field() ?>
                                    <button class="button button--small button--danger" type="submit">移除</button>
                                </form>
                            <?php elseif ($member['role'] === 'owner'): ?>
                                <span class="manage-note">不可移除</span>
                            <?php else: ?>
                                <span class="manage-note">不能移除自己</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="manage-section">
            <h2>待接受的邀请</h2>
            <?php if ($invitations === []): ?>
                <p class="manage-empty">没有待接受的邀请。</p>
            <?php else: ?>
                <table class="manage-table">
                    <thead>
                    <tr>
                        <th>受邀人</th>
                        <th>角色</th>
                        <th>邀请链接</th>
                        <th>有效期至</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invitations as $invitation): ?>
                        <tr>
                            <td>
                                <?= e($invitation['email'] ?: '未指定邮箱') ?>
                                <div class="manage-note">由 <?= e($invitation['creator_name'] ?: '馆主') ?> 创建</div>
                            </td>
                            <td><?= e(role_label((string) $invitation['role'])) ?></td>
                            <td>
                                <input class="manage-invite-link" type="text" readonly value="<?= e($invitation['invite_url']) ?>" aria-label="邀请链接">
                                <div class="manage-note" style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
                                    <button class="button button--small button--ghost" type="button" data-copy="<?= e($invitation['invite_url']) ?>">复制链接</button>
                                    <a href="<?= e($invitation['invite_url']) ?>" target="_blank" rel="noopener">打开链接</a>
                                </div>
                            </td>
                            <td><?= e(format_datetime($invitation['expires_at'])) ?></td>
                            <td>
                                <form method="post" action="<?= e(url($base . '/invitations/' . (int) $invitation['id'] . '/revoke')) ?>" data-confirm="确认作废这条邀请？链接将立即失效。">
                                    <?= csrf_field() ?>
                                    <button class="button button--small button--danger" type="submit">作废</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
