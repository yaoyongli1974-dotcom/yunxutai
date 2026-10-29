<?php
use App\Core\View;

$statusLabels = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回'];
$query = ['status' => $status];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php View::partial('admin/_nav', ['active' => 'verifications']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">身份与授权</p>
                    <h1>身份核验</h1>
                    <p class="muted">核对死亡证明、亲属关系材料与申领记录。通过后馆主才能将纪念馆设为公开或不公开收录。</p>
                </div>
            </div>

            <div class="admin-note admin-note--warning">
                材料含敏感个人信息，仅限授权审核人员查看。下载与审核操作都会写入审计日志；请勿转发、截图外传或用于其他目的。
            </div>

            <nav class="tabs" aria-label="核验状态">
                <?php foreach ($statusLabels as $value => $label): ?>
                    <a href="<?= e(url('/admin/verifications?status=' . $value)) ?>"<?= $status === $value ? ' aria-current="page"' : '' ?>>
                        <?= e($label) ?> <?= (int) ($counts[$value] ?? 0) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>纪念馆</th>
                        <th>提交人</th>
                        <th>材料</th>
                        <th>状态</th>
                        <th>提交时间</th>
                        <th>处理</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($items === []): ?>
                        <tr><td colspan="6" class="muted">当前没有<?= e($statusLabels[$status] ?? '') ?>的核验申请。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?= e($item['deceased_name']) ?></strong>
                                <p class="muted"><?= e($item['memorial_title']) ?></p>
                            </td>
                            <td>
                                <?= e($item['submitter_name']) ?>
                                <p class="muted">#<?= (int) $item['submitted_by'] ?></p>
                            </td>
                            <td><?= e(verification_method_label((string) $item['method'])) ?></td>
                            <td>
                                <span class="chip<?= $item['status'] === 'approved' ? ' chip--accent' : ($item['status'] === 'rejected' ? ' chip--warn' : '') ?>">
                                    <?= e($statusLabels[$item['status']] ?? $item['status']) ?>
                                </span>
                            </td>
                            <td><?= e(format_datetime((string) $item['created_at'])) ?></td>
                            <td><a class="button button--small button--ghost" href="<?= e(url('/admin/verifications/' . (int) $item['id'])) ?>">查看</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php View::partial('admin/_pagination', ['paginator' => $paginator, 'path' => '/admin/verifications', 'query' => $query]); ?>
        </div>
    </div>
</section>
