<?php
$actionLabels = [
    'auth.login' => '登录',
    'auth.logout' => '退出',
    'memorial.create' => '创建纪念馆',
    'memorial.update_profile' => '更新纪念馆资料',
    'memorial.update_settings' => '更新纪念馆设置',
    'memorial.publish' => '发布纪念馆',
    'memorial.pause_owner' => '馆主暂停展示',
    'memorial.resume_owner' => '馆主恢复展示',
    'memorial.suspend_admin' => '平台暂停纪念馆',
    'admin.memorial.suspend' => '平台暂停纪念馆',
    'admin.memorial.restore' => '平台恢复纪念馆',
    'admin.message.status' => '审核留言',
    'admin.article.approve' => '通过文章',
    'admin.article.reject' => '驳回文章',
    'admin.media.hide' => '隐藏媒体',
    'admin.media.show' => '恢复媒体',
    'admin.report.resolve' => '处理举报',
    'admin.report.hide_target' => '隐藏举报目标',
    'admin.report.suspend_memorial' => '暂停纪念馆',
    'admin.claim.approved' => '通过申领',
    'admin.claim.rejected' => '驳回申领',
    'admin.data_request.update' => '更新数据申请',
    'admin.service_request.update' => '更新服务申请',
    'admin.user.enable' => '启用账号',
    'admin.user.disable' => '禁用账号',
    'report.create' => '提交举报',
    'claim.create' => '提交申领',
];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'logs']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">审计</p>
                    <h1>操作日志</h1>
                    <p class="muted">记录平台与用户的关键操作。IP 仅保存哈希值，显示前 12 位以便追溯。</p>
                </div>
            </div>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/logs')) ?>">
                <label class="field">
                    <span>搜索</span>
                    <input type="search" name="q" value="<?= e($query) ?>" maxlength="80" placeholder="操作者、action 或目标类型">
                </label>
                <button class="button button--small" type="submit">搜索</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/logs')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>时间</th>
                        <th>操作者</th>
                        <th>操作</th>
                        <th>目标</th>
                        <th>附加信息</th>
                        <th>IP 哈希</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($logs === []): ?>
                        <tr><td colspan="6" class="muted">没有符合条件的日志。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="muted" style="white-space:nowrap;"><?= e(format_datetime($log['created_at'])) ?></td>
                            <td><?= e($log['display_name'] ?: '系统 / 访客') ?></td>
                            <td>
                                <strong><?= e($actionLabels[$log['action']] ?? $log['action']) ?></strong>
                                <p class="muted"><?= e($log['action']) ?></p>
                            </td>
                            <td>
                                <?php if (!empty($log['target_type'])): ?>
                                    <?= e($log['target_type']) ?><?= !empty($log['target_id']) ? ' #' . (int) $log['target_id'] : '' ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['meta_summary'] !== ''): ?>
                                    <?php if (mb_strlen($log['meta_summary']) > 90): ?>
                                        <details>
                                            <summary class="text-link" style="cursor:pointer;font-size:0.8rem;">查看附加信息</summary>
                                            <p class="muted" style="font-size:0.8rem;"><?= e($log['meta_summary']) ?></p>
                                        </details>
                                    <?php else: ?>
                                        <span class="muted" style="font-size:0.8rem;"><?= e($log['meta_summary']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= e($log['ip_short'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/logs',
                'query' => $baseQuery,
            ]); ?>
        </div>
    </div>
</section>
