<?php
$statusOptions = ['' => '全部状态', 'open' => '待处理', 'approved' => '已通过', 'rejected' => '已驳回'];
$claimStatusLabels = ['open' => '待处理', 'approved' => '已通过', 'rejected' => '已驳回'];
$oldId = (int) ($old['id'] ?? 0);
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'claims']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">权益申请</p>
                    <h1>申领管理</h1>
                    <p class="muted">处理纪念馆管理权限申领。需核验身份与授权材料后操作。</p>
                </div>
            </div>

            <div class="admin-note admin-note--warning">
                申领涉及身份与亲属关系认定。通过申领仅将申请人设为该纪念馆的协作者（editor），不会自动转移馆主；馆主变更须另行线下核验并留存授权材料。
            </div>

            <?php foreach ($errors as $message): ?>
                <div class="form-error" role="alert" style="margin-bottom:0.8rem;"><?= e($message) ?></div>
            <?php endforeach; ?>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/claims')) ?>">
                <label class="field">
                    <span>状态</span>
                    <select name="status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--small" type="submit">筛选</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/claims')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>纪念馆</th>
                        <th>申请人</th>
                        <th>关系与说明</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th>处理</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($claims === []): ?>
                        <tr><td colspan="6" class="muted">没有符合条件的申领。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($claims as $claim): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/admin/memorials/' . (int) $claim['memorial_id'])) ?>"><?= e($claim['memorial_name']) ?></a>
                                <p class="muted">/m/<?= e($claim['slug']) ?></p>
                            </td>
                            <td>
                                <strong><?= e($claim['display_name']) ?></strong>
                                <p class="muted"><?= e($claim['email']) ?></p>
                            </td>
                            <td>
                                <p><strong><?= e($claim['relationship']) ?></strong></p>
                                <details>
                                    <summary class="text-link" style="cursor:pointer;font-size:0.82rem;">查看申领说明</summary>
                                    <p style="margin-top:0.4rem;"><?= nl2br(e($claim['statement'])) ?></p>
                                    <?php if (!empty($claim['evidence_note'])): ?>
                                        <p class="muted">证明材料说明：<?= nl2br(e($claim['evidence_note'])) ?></p>
                                    <?php endif; ?>
                                </details>
                            </td>
                            <td><span class="admin-badge admin-badge--<?= e($claim['status']) ?>"><?= e($claimStatusLabels[$claim['status']] ?? $claim['status']) ?></span></td>
                            <td class="muted">
                                <?= e(format_datetime($claim['created_at'])) ?>
                                <?php if (!empty($claim['handled_at'])): ?>
                                    <p>处理：<?= e(format_datetime($claim['handled_at'])) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($claim['status'] === 'open'): ?>
                                    <form class="admin-inline-form" method="post" action="<?= e(url('/admin/claims/' . (int) $claim['id'])) ?>">
                                        <?= csrf_field() ?>
                                        <label class="field">
                                            <span>处理方式</span>
                                            <select name="action" required>
                                                <option value="approve"<?= $oldId === (int) $claim['id'] && ($old['action'] ?? '') === 'approve' ? ' selected' : '' ?>>通过，授予协作者</option>
                                                <option value="reject"<?= $oldId === (int) $claim['id'] && ($old['action'] ?? '') === 'reject' ? ' selected' : '' ?>>驳回</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>处理说明（最多 500 字）</span>
                                            <textarea name="resolution" maxlength="500" placeholder="核验结论、角色说明或驳回理由"><?= e($oldId === (int) $claim['id'] ? ($old['resolution'] ?? '') : '') ?></textarea>
                                        </label>
                                        <button class="button button--small" type="submit">保存处理</button>
                                        <p class="muted" style="font-size:0.76rem;margin:0;width:100%;">请确认已核验身份与授权材料。通过仅授予协作者权限。</p>
                                    </form>
                                <?php else: ?>
                                    <p class="muted" style="font-size:0.82rem;"><?= e($claim['resolution'] ?: '未填写处理说明') ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/claims',
                'query' => $query,
            ]); ?>
        </div>
    </div>
</section>
