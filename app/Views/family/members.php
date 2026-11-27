<?php
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $members */
/** @var list<array<string,mixed>> $invitations */
/** @var list<string> $errors */
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow">家族管理</p><h1>家族成员</h1>
    <?php foreach ($errors as $error): ?><div class="banner banner--veil"><p><?= e($error) ?></p></div><?php endforeach; ?>
    <div class="grid grid--2">
        <section class="card">
            <h2>现有成员</h2>
            <table class="table"><thead><tr><th>成员</th><th>角色</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($members as $member): ?>
                <tr>
                    <td><?= e($member['display_name'] ?? $member['email']) ?></td>
                    <td><?= e($member['role']) ?>（<?= e($member['status']) ?>）</td>
                    <td>
                        <?php if ($member['role'] !== 'owner'): ?>
                            <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/members/' . (int) $member['user_id'] . '/role')) ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <select name="role">
                                    <?php foreach (\App\Core\Policy::FAMILY_ROLES as $role): ?>
                                        <option value="<?= e($role) ?>" <?= $member['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="button button--small button--ghost" type="submit">调整</button>
                            </form>
                            <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/members/' . (int) $member['user_id'] . '/remove')) ?>" style="display:inline;">
                                <?= csrf_field() ?><button class="button button--small button--ghost" type="submit">移除</button>
                            </form>
                        <?php else: ?>创建者<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
        <div>
            <section class="card">
                <h2>邀请成员</h2>
                <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/members/invite')) ?>">
                    <?= csrf_field() ?>
                    <label class="field">邮箱 <input type="email" name="email" required></label>
                    <label class="field">角色
                        <select name="role">
                            <?php foreach (\App\Services\FamilyService::INVITABLE_ROLES as $role): ?>
                                <option value="<?= e($role) ?>"><?= e($role) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button" type="submit">创建邀请</button>
                </form>
            </section>
            <section class="card" style="margin-top:1rem;">
                <h2>待接受邀请</h2>
                <?php if ($invitations === []): ?><p class="muted">暂无待接受邀请。</p><?php endif; ?>
                <?php foreach ($invitations as $invite): ?>
                    <p class="small"><?= e($invite['email']) ?> · <?= e($invite['role']) ?> · 至 <?= e($invite['expires_at']) ?></p>
                    <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/invitations/' . (int) $invite['id'] . '/revoke')) ?>">
                        <?= csrf_field() ?><button class="button button--small button--ghost" type="submit">撤销</button>
                    </form>
                <?php endforeach; ?>
            </section>
        </div>
    </div>
    <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/leave')) ?>" style="margin-top:1rem;">
        <?= csrf_field() ?><button class="button button--small button--ghost" type="submit">退出家族</button>
    </form>
</div>
