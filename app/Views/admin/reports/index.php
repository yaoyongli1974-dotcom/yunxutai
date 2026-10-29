<?php
$statusOptions = ['' => '全部状态', 'open' => '待处理', 'reviewing' => '处理中', 'resolved' => '已处理', 'dismissed' => '已驳回'];
$targetLabels = ['memorial' => '纪念馆', 'message' => '留言', 'tribute' => '祭奠记录', 'media' => '影像', 'article' => '文章'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'reports']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">治理队列</p>
                    <h1>举报处理</h1>
                    <p class="muted">核对举报事实与目标内容，登记处理结论。涉及身份争议的，先核验材料再暂停展示。</p>
                </div>
            </div>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/reports')) ?>">
                <label class="field">
                    <span>状态</span>
                    <select name="status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--small" type="submit">筛选</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/reports')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>举报目标</th>
                        <th>纪念馆</th>
                        <th>原因</th>
                        <th>优先级</th>
                        <th>举报人</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($reports === []): ?>
                        <tr><td colspan="9" class="muted">没有符合条件的举报。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td>#<?= (int) $report['id'] ?></td>
                            <td>
                                <?= e($targetLabels[$report['target_type']] ?? $report['target_type']) ?> #<?= (int) $report['target_id'] ?>
                                <?php if (!empty($report['detail'])): ?>
                                    <p class="muted"><?= e(excerpt_text($report['detail'], 60)) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($report['memorial_id'])): ?>
                                    <a href="<?= e(url('/admin/memorials/' . (int) $report['memorial_id'])) ?>"><?= e($report['memorial_name'] ?: '纪念馆 #' . (int) $report['memorial_id']) ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(report_reason_label($report['reason'])) ?></td>
                            <td><span class="chip<?= $report['priority'] === 'urgent' ? ' chip--warn' : ($report['priority'] === 'high' ? ' chip--accent' : '') ?>"><?= e(report_priority_label((string) $report['priority'])) ?></span></td>
                            <td><?= e($report['reporter_display'] ?: $report['reporter_name'] ?: '访客') ?></td>
                            <td><span class="admin-badge admin-badge--<?= e($report['status']) ?>"><?= e(status_label($report['status'])) ?></span></td>
                            <td class="muted"><?= e(format_datetime($report['created_at'])) ?></td>
                            <td><a class="text-link" href="<?= e(url('/admin/reports/' . (int) $report['id'])) ?>">查看处理</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/reports',
                'query' => $query,
            ]); ?>
        </div>
    </div>
</section>
