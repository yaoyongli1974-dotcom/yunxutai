<?php
$currentAdmin = auth_user();
$statusLabels = ['active' => '正常', 'disabled' => '已禁用'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'users']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">用户</p>
                    <h1>用户管理</h1>
                    <p class="muted">查询用户并处理账号启用或禁用。后台不提供角色修改入口。</p>
                </div>
            </div>

            <?php foreach ($errors as $message): ?>
                <div class="form-error" role="alert" style="margin-bottom:0.8rem;"><?= e($message) ?></div>
            <?php endforeach; ?>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/users')) ?>">
                <label class="field">
                    <span>搜索</span>
                    <input type="search" name="q" value="<?= e($query) ?>" maxlength="80" placeholder="邮箱或称呼">
                </label>
                <button class="button button--small" type="submit">搜索</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/users')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户</th>
                        <th>角色</th>
                        <th>状态</th>
                        <th>二次验证</th>
                        <th>最后登录</th>
                        <th>注册时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($users === []): ?>
                        <tr><td colspan="8" class="muted">没有符合条件的用户。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $account): ?>
                        <tr>
                            <td><?= (int) $account['id'] ?></td>
                            <td>
                                <strong><?= e($account['display_name']) ?></strong>
                                <p class="muted"><?= e($account['email']) ?></p>
                            </td>
                            <td><?= e(role_label($account['role'])) ?></td>
                            <td><span class="admin-badge admin-badge--<?= e($account['status']) ?>"><?= e($statusLabels[$account['status']] ?? $account['status']) ?></span></td>
                            <td>
                                <?php if ((int) ($account['totp_enabled'] ?? 0) === 1): ?>
                                    <span class="admin-badge admin-badge--active">已启用</span>
                                <?php else: ?>
                                    <span class="admin-badge admin-badge--draft">未启用</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= e(format_datetime($account['last_login_at']) ?: '从未登录') ?></td>
                            <td class="muted"><?= e(format_datetime($account['created_at'])) ?></td>
                            <td>
                                <?php if ($currentAdmin !== null && (int) $currentAdmin['id'] === (int) $account['id']): ?>
                                    <span class="muted">当前登录账号</span>
                                <?php elseif ($account['status'] === 'active'): ?>
                                    <form method="post" action="<?= e(url('/admin/users/' . (int) $account['id'] . '/status')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="disable">
                                        <button class="button button--small button--ghost" type="submit">禁用账号</button>
                                        <p class="muted" style="font-size:0.74rem;margin:0.3rem 0 0;">禁用后无法登录，可再次启用；不会删除内容。</p>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('/admin/users/' . (int) $account['id'] . '/status')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="enable">
                                        <button class="button button--small" type="submit">启用账号</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) ($account['totp_enabled'] ?? 0) === 1): ?>
                                    <form method="post" action="<?= e(url('/admin/users/' . (int) $account['id'] . '/totp/reset')) ?>" style="margin-top:0.45rem;">
                                        <?= csrf_field() ?>
                                        <button class="button button--small button--danger" type="submit">重置二次验证</button>
                                        <p class="muted" style="font-size:0.74rem;margin:0.3rem 0 0;">用于手机丢失解锁；重置后仅凭密码即可登录。</p>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/users',
                'query' => $baseQuery,
            ]); ?>
        </div>
    </div>
</section>
