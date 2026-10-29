<?php
$statusOptions = ['' => '全部状态', 'open' => '待处理', 'processing' => '处理中', 'done' => '已完成', 'rejected' => '已驳回'];
$typeLabels = ['export' => '数据导出', 'delete' => '数据删除', 'revoke' => '撤回授权'];
$oldId = (int) ($old['id'] ?? 0);
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'data-requests']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">用户请求</p>
                    <h1>数据申请</h1>
                    <p class="muted">登记数据导出、删除与撤回授权的处理进度。</p>
                </div>
            </div>

            <div class="admin-note admin-note--warning">
                数据申请须先核验申请人身份、与纪念馆的授权关系及申请范围。本页仅登记处理状态，不会执行硬删除；导出、删除或撤回授权须在线下核验后处理，并保留操作记录。
            </div>

            <?php foreach ($errors as $message): ?>
                <div class="form-error" role="alert" style="margin-bottom:0.8rem;"><?= e($message) ?></div>
            <?php endforeach; ?>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/data-requests')) ?>">
                <label class="field">
                    <span>状态</span>
                    <select name="status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--small" type="submit">筛选</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/data-requests')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>用户</th>
                        <th>类型</th>
                        <th>说明</th>
                        <th>关联纪念馆</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th>处理</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests === []): ?>
                        <tr><td colspan="7" class="muted">没有符合条件的数据申请。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $dataRequest): ?>
                        <tr>
                            <td>
                                <strong><?= e($dataRequest['display_name']) ?></strong>
                                <p class="muted"><?= e($dataRequest['email']) ?></p>
                            </td>
                            <td><?= e($typeLabels[$dataRequest['type']] ?? $dataRequest['type']) ?></td>
                            <td>
                                <?php if (!empty($dataRequest['note'])): ?>
                                    <?= nl2br(e(excerpt_text($dataRequest['note'], 220))) ?>
                                <?php else: ?>
                                    <span class="muted">未填写</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($dataRequest['memorial_id'])): ?>
                                    <a href="<?= e(url('/admin/memorials/' . (int) $dataRequest['memorial_id'])) ?>">纪念馆 #<?= (int) $dataRequest['memorial_id'] ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="admin-badge admin-badge--<?= e($dataRequest['status']) ?>"><?= e(status_label($dataRequest['status'])) ?></span></td>
                            <td class="muted">
                                <?= e(format_datetime($dataRequest['created_at'])) ?>
                                <?php if (!empty($dataRequest['handled_at'])): ?>
                                    <p>处理：<?= e(format_datetime($dataRequest['handled_at'])) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-inline-form" method="post" action="<?= e(url('/admin/data-requests/' . (int) $dataRequest['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <label class="field">
                                        <span>状态</span>
                                        <select name="status" required>
                                            <?php foreach (['processing' => '处理中', 'done' => '已完成', 'rejected' => '已驳回'] as $value => $label): ?>
                                                <option value="<?= e($value) ?>"<?= $oldId === (int) $dataRequest['id'] && ($old['status'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span>处理说明（最多 500 字）</span>
                                        <textarea name="resolution" maxlength="500" placeholder="核验结果、执行方式或驳回理由"><?= e($oldId === (int) $dataRequest['id'] ? ($old['resolution'] ?? '') : (string) ($dataRequest['resolution'] ?? '')) ?></textarea>
                                    </label>
                                    <button class="button button--small" type="submit">登记状态</button>
                                    <p class="muted" style="font-size:0.76rem;margin:0;width:100%;">仅登记进度；不会在此删除数据。</p>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/data-requests',
                'query' => $query,
            ]); ?>
        </div>
    </div>
</section>
