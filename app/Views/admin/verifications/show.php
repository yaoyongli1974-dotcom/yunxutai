<?php
use App\Core\View;

$statusLabels = ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已驳回'];
$m = $memorial;
$oldNote = (string) ($old['review_note'] ?? '');
$oldAction = (string) ($old['action'] ?? 'approve');
$status = (string) $verification['status'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php View::partial('admin/_nav', ['active' => 'verifications']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">核验材料 #<?= (int) $verification['id'] ?></p>
                    <h1><?= e($verification['deceased_name']) ?></h1>
                    <p class="muted">
                        <?= e($verification['memorial_title']) ?>
                        <?php if ($m !== null): ?>
                            · <a href="<?= e(url('/admin/memorials/' . (int) $m['id'])) ?>">查看纪念馆</a>
                            · <a href="<?= e(url('/m/' . $m['slug'])) ?>">公开页面</a>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="chip<?= $status === 'approved' ? ' chip--accent' : ($status === 'rejected' ? ' chip--warn' : '') ?>">
                    <?= e($statusLabels[$status] ?? $status) ?>
                </span>
            </div>

            <?php foreach ($errors as $key => $message): ?>
                <div class="form-error" role="alert" style="margin-bottom:0.8rem;"><?= e($message) ?></div>
            <?php endforeach; ?>

            <div class="manage-grid">
                <div class="manage-section">
                    <h2>提交信息</h2>
                    <dl class="manage-facts">
                        <div><dt>提交人</dt><dd><?= e($verification['submitter_name']) ?>（<?= e($verification['submitter_email']) ?>）</dd></div>
                        <div><dt>材料类型</dt><dd><?= e(verification_method_label((string) $verification['method'])) ?></dd></div>
                        <div><dt>提交时间</dt><dd><?= e(format_datetime((string) $verification['created_at'])) ?></dd></div>
                        <div><dt>关系与说明</dt><dd><?= nl2br(e((string) $verification['statement'])) ?></dd></div>
                        <div><dt>纪念馆状态</dt><dd><?= e(status_label((string) ($m['status'] ?? 'draft'))) ?> · <?= e(verification_label((string) ($m['verification_status'] ?? 'none'))) ?></dd></div>
                    </dl>
                </div>

                <div class="manage-section">
                    <h2>材料文件</h2>
                    <?php if (!empty($verification['document_path'])): ?>
                        <p><?= e((string) $verification['document_name']) ?></p>
                        <p class="muted"><?= e((string) $verification['document_mime']) ?> · <?= number_format(((int) $verification['document_size']) / 1024, 1) ?> KB</p>
                        <a class="button button--small" href="<?= e(url('/admin/verifications/' . (int) $verification['id'] . '/document')) ?>" target="_blank" rel="noopener">在浏览器中查看材料</a>
                        <p class="muted" style="margin-top:0.8rem;">材料可能含敏感个人信息。查看行为会记录审计日志。</p>
                    <?php else: ?>
                        <p class="muted">本次申请没有上传文件。仅在已通过线下核验时，才可人工通过。</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($status === 'pending'): ?>
                <section class="manage-section">
                    <h2>审核结论</h2>
                    <form class="form" method="post" action="<?= e(url('/admin/verifications/' . (int) $verification['id'] . '/review')) ?>">
                        <?= csrf_field() ?>
                        <div class="manage-grid">
                            <label class="field">
                                <span>处理方式</span>
                                <select name="action">
                                    <option value="approve"<?= $oldAction === 'approve' ? ' selected' : '' ?>>通过核验</option>
                                    <option value="reject"<?= $oldAction === 'reject' ? ' selected' : '' ?>>驳回并请馆主补充</option>
                                </select>
                            </label>
                            <label class="field field--wide">
                                <span>审核说明</span>
                                <textarea name="review_note" maxlength="500" style="min-height:100px;" placeholder="通过时可留空；驳回时请说明原因与需要补充的材料。"><?= e($oldNote) ?></textarea>
                                <?php if (!empty($errors['review_note'])): ?><small class="field__error"><?= e($errors['review_note']) ?></small><?php endif; ?>
                            </label>
                        </div>
                        <div class="admin-note admin-note--warning">
                            通过前请确认：材料姓名与逝者一致、出具机构与时间合理、申请人与逝者关系成立。无法确认时选择驳回，不要凭印象通过。
                        </div>
                        <div class="manage-actions-row">
                            <button class="button" type="submit" data-confirm="确认提交审核结论？该操作会写入审计日志。">提交结论</button>
                            <a class="button button--ghost" href="<?= e(url('/admin/verifications')) ?>">返回列表</a>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <section class="manage-section">
                    <h2>审核结果</h2>
                    <p>
                        <?= e($statusLabels[$status] ?? $status) ?>
                        <?php if (!empty($verification['reviewed_at'])): ?>
                            · <?= e(format_datetime((string) $verification['reviewed_at'])) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($verification['review_note'])): ?>
                        <p><?= nl2br(e((string) $verification['review_note'])) ?></p>
                    <?php endif; ?>
                    <a class="button button--ghost" href="<?= e(url('/admin/verifications')) ?>">返回列表</a>
                </section>
            <?php endif; ?>
        </div>
    </div>
</section>
