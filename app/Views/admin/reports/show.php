<?php
$targetLabels = ['memorial' => '纪念馆', 'message' => '留言', 'tribute' => '祭奠记录', 'media' => '影像', 'article' => '文章'];
$hideable = ['message' => '留言', 'tribute' => '祭奠记录', 'media' => '影像资料', 'article' => '文章'];
$currentStatus = $old['status'] ?? (in_array($report['status'], ['reviewing', 'resolved', 'dismissed'], true) ? $report['status'] : 'reviewing');
$currentResolution = $old['resolution'] ?? (string) ($report['resolution'] ?? '');
$canSuspend = !empty($report['memorial_id']) || ($target !== null && $target['type'] === 'memorial');
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'reports']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow"><a class="text-link" href="<?= e(url('/admin/reports')) ?>">← 返回举报列表</a></p>
                    <h1>举报 #<?= (int) $report['id'] ?></h1>
                    <p class="muted">提交于 <?= e(format_datetime($report['created_at'])) ?> · <?= e(report_reason_label($report['reason'])) ?> · 优先级：<?= e(report_priority_label((string) $report['priority'])) ?></p>
                </div>
                <span class="admin-badge admin-badge--<?= e($report['status']) ?>"><?= e(status_label($report['status'])) ?></span>
            </div>

            <?php if (($report['priority'] ?? 'normal') === 'urgent'): ?>
                <div class="admin-note admin-note--warning">
                    紧急事项：涉及在世者被祭奠或虚假逝者信息。请优先核实；如线索可信，可先行暂停纪念馆，再通知创建人补充核验材料。
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['form'])): ?>
                <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
            <?php endif; ?>

            <div class="admin-note admin-note--warning">
                处理举报前需核验举报人身份、授权材料与目标内容事实。平台只暂停展示或隐藏内容，不提供一键永久删除。
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <h2>举报目标</h2>
                        <span class="muted"><?= e($targetLabels[$report['target_type']] ?? $report['target_type']) ?> #<?= (int) $report['target_id'] ?></span>
                    </div>
                    <?php if ($target === null): ?>
                        <p class="admin-empty">目标内容已不存在，可能已被删除或状态变更。仍可登记处理结论，但无法执行隐藏。</p>
                    <?php else: ?>
                        <p><strong><?= e($target['label']) ?></strong></p>
                        <p class="muted" style="font-size:0.84rem;">
                            当前状态：<span class="admin-badge admin-badge--<?= e($target['status']) ?>"><?= e(status_label($target['status'])) ?></span>
                        </p>
                        <?php if ($target['summary'] !== ''): ?>
                            <p><?= nl2br(e(excerpt_text($target['summary'], 500))) ?></p>
                        <?php endif; ?>
                        <p class="muted" style="font-size:0.84rem;">
                            所属纪念馆：
                            <a href="<?= e(url('/admin/memorials/' . (int) $target['memorial']['id'])) ?>"><?= e($target['memorial']['title']) ?></a>
                            <?php if ($target['memorial']['status'] === 'active'): ?>
                                · <a href="<?= e(url('/m/' . $target['memorial']['slug'])) ?>" target="_blank" rel="noopener">查看前台</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <h2>举报信息</h2>
                    </div>
                    <dl class="admin-definition">
                        <div><dt>举报类型</dt><dd><?= e(report_reason_label($report['reason'])) ?></dd></div>
                        <div><dt>举报人</dt><dd><?= e($report['reporter_display'] ?? ($report['reporter_name'] ?: '访客')) ?></dd></div>
                        <div><dt>联系方式</dt><dd><?= e($report['contact'] ?: '未填写') ?></dd></div>
                        <div><dt>提交时间</dt><dd><?= e(format_datetime($report['created_at'])) ?></dd></div>
                        <?php if ($report['handled_at']): ?>
                            <div><dt>最近处理</dt><dd><?= e(format_datetime($report['handled_at'])) ?><?php if ($handler): ?> · <?= e($handler['display_name']) ?><?php endif; ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <p style="margin-top:1rem;font-size:0.9rem;"><?= nl2br(e($report['detail'] ?: '举报人未填写补充说明。')) ?></p>
                    <?php if (!empty($report['resolution'])): ?>
                        <div class="admin-note" style="margin:0.8rem 0 0;">
                            <strong>上次处理说明：</strong><?= e($report['resolution']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-panel">
                <h2>处理举报</h2>
                <p class="muted">选择处理状态并记录结论。标记为已处理或已驳回时，处理说明为必填。</p>

                <?php foreach ($errors as $field => $message): ?>
                    <?php if ($field !== 'form'): ?>
                        <div class="form-error" role="alert" style="margin-bottom:0.6rem;"><?= e($message) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <form class="form" method="post" action="<?= e(url('/admin/reports/' . (int) $report['id'] . '/resolve')) ?>">
                    <?= csrf_field() ?>

                    <label class="field">
                        <span>处理状态</span>
                        <select name="status" required>
                            <option value="reviewing"<?= $currentStatus === 'reviewing' ? ' selected' : '' ?>>处理中（继续核查）</option>
                            <option value="resolved"<?= $currentStatus === 'resolved' ? ' selected' : '' ?>>已处理</option>
                            <option value="dismissed"<?= $currentStatus === 'dismissed' ? ' selected' : '' ?>>已驳回</option>
                        </select>
                        <?php if (!empty($errors['status'])): ?><small class="field__error"><?= e($errors['status']) ?></small><?php endif; ?>
                    </label>

                    <label class="field">
                        <span>处理说明（最多 500 字）</span>
                        <textarea name="resolution" maxlength="500" placeholder="记录核验过程、事实认定与处理依据。"><?= e($currentResolution) ?></textarea>
                        <small class="field__hint">已处理或已驳回时必填；说明会展示在举报记录中。</small>
                        <?php if (!empty($errors['resolution'])): ?><small class="field__error"><?= e($errors['resolution']) ?></small><?php endif; ?>
                    </label>

                    <div class="admin-note">
                        <strong>可选处置</strong>
                        <p class="muted" style="margin:0.3rem 0;">勾选后会立即执行对应操作，并写入操作日志。请确认已完成核验。驳回举报时不要勾选。</p>

                        <?php if ($target !== null && isset($hideable[$target['type']])): ?>
                            <label class="checkbox">
                                <input type="checkbox" name="hide_target" value="1"<?= !empty($old['hide_target']) ? ' checked' : '' ?>>
                                <span>隐藏对应<?= e($hideable[$target['type']]) ?>（当前状态：<?= e(status_label($target['status'])) ?>）</span>
                            </label>
                            <?php if (!empty($errors['hide_target'])): ?><small class="field__error"><?= e($errors['hide_target']) ?></small><?php endif; ?>
                        <?php elseif ($report['target_type'] === 'memorial'): ?>
                            <p class="muted" style="font-size:0.84rem;">纪念馆目标请使用下方“暂停相关纪念馆”，不单独隐藏纪念馆。</p>
                        <?php endif; ?>

                        <?php if ($canSuspend): ?>
                            <label class="checkbox" style="margin-top:0.6rem;">
                                <input type="checkbox" name="suspend_memorial" value="1"<?= !empty($old['suspend_memorial']) ? ' checked' : '' ?>>
                                <span>暂停相关纪念馆（公开页面将不可访问，数据保留）</span>
                            </label>
                            <?php if (!empty($errors['suspend_memorial'])): ?><small class="field__error"><?= e($errors['suspend_memorial']) ?></small><?php endif; ?>

                            <label class="field" style="margin-top:0.6rem;">
                                <span>暂停理由（勾选暂停时必填，5–500 字）</span>
                                <textarea name="suspend_reason" maxlength="500" placeholder="写明已核实的身份争议、侵权事实或违规依据。"><?= e($old['suspend_reason'] ?? '') ?></textarea>
                                <?php if (!empty($errors['suspend_reason'])): ?><small class="field__error"><?= e($errors['suspend_reason']) ?></small><?php endif; ?>
                            </label>
                        <?php endif; ?>
                    </div>

                    <p class="muted" style="font-size:0.84rem;">提交前请确认处理结论与已核实的事实一致。该操作不会永久删除任何内容。</p>
                    <div>
                        <button class="button" type="submit">保存处理结果</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
