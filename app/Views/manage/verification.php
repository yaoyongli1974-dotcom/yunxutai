<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$status = (string) ($memorial['verification_status'] ?? 'none');
$oldStatement = (string) ($old['statement'] ?? '');
$oldMethod = (string) ($old['method'] ?? 'death_certificate');
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'verification']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">身份核验</p>
                <h1>确认这是一位真实逝者的纪念空间</h1>
                <p class="muted" style="margin:0;">为防止活人被误建纪念馆、冒用他人身份或恶意传播，公开纪念空间需要先完成身份材料核验。</p>
            </div>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <div class="manage-status-row">
                <span class="chip<?= $status === 'verified' ? ' chip--accent' : ($status === 'rejected' ? ' chip--warn' : '') ?>">
                    <?= e(verification_label($status)) ?>
                </span>
                <?php if ($status === 'verified'): ?>
                    <span class="muted">
                        <?= e(verification_method_label((string) ($memorial['verification_method'] ?? ''))) ?>
                        · <?= e(format_date((string) ($memorial['verified_at'] ?? ''))) ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($status === 'verified'): ?>
                <p>该纪念馆已完成身份核验，可以在 <a href="<?= e(url($base . '/settings')) ?>">访问与留言</a> 中选择“公开”或“不公开收录”。</p>
                <p class="muted">核验材料仅用于平台审核，不会出现在公开页面，也不会通过任何公开链接访问。</p>
            <?php elseif ($status === 'pending'): ?>
                <p><strong>材料正在审核中。</strong>平台会人工核对材料与说明，通常需要 1–3 个工作日。</p>
                <?php if ($latest !== null): ?>
                    <dl class="manage-facts">
                        <div><dt>材料类型</dt><dd><?= e(verification_method_label((string) $latest['method'])) ?></dd></div>
                        <div><dt>提交时间</dt><dd><?= e(format_datetime((string) $latest['created_at'])) ?></dd></div>
                        <div><dt>说明</dt><dd><?= nl2br(e((string) $latest['statement'])) ?></dd></div>
                    </dl>
                <?php endif; ?>
                <p class="muted">审核期间纪念馆保持私密；无需重复提交。如需补充材料，可在审核结果出来后重新提交。</p>
            <?php else: ?>
                <?php if ($status === 'rejected' && $latest !== null): ?>
                    <div class="form-error" role="alert">
                        上次核验未通过<?= !empty($latest['review_note']) ? '：' . e((string) $latest['review_note']) : '。' ?>
                        请补充或更换材料后重新提交。
                    </div>
                <?php endif; ?>

                <form class="form manage-form" method="post" action="<?= e(url($base . '/verification')) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <label class="field">
                        <span>材料类型</span>
                        <select name="method">
                            <?php foreach ($methods as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $oldMethod === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['method'])): ?><small class="field__error"><?= e($errors['method']) ?></small><?php endif; ?>
                    </label>

                    <label class="field field--wide">
                        <span>你与逝者的关系，以及材料来源</span>
                        <textarea name="statement" maxlength="2000" style="min-height:120px;" placeholder="例如：我是逝者的长女。上传的是医院出具的死亡医学证明；如材料上的姓名与纪念馆不一致，请说明原因。"><?= e($oldStatement) ?></textarea>
                        <small class="field__hint">20–2000 字。请只提供核验所必需的信息，不要上传身份证号以外的无关隐私。</small>
                        <?php if (!empty($errors['statement'])): ?><small class="field__error"><?= e($errors['statement']) ?></small><?php endif; ?>
                    </label>

                    <label class="field field--wide">
                        <span>核验材料</span>
                        <input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required>
                        <small class="field__hint">支持 JPG、PNG、WebP、PDF，单份不超过 10MB。可上传死亡证明、死亡登记材料、亲属关系证明等；敏感信息可先遮挡与核验无关的部分。</small>
                        <?php if (!empty($errors['document'])): ?><small class="field__error"><?= e($errors['document']) ?></small><?php endif; ?>
                    </label>

                    <div class="notice-card">
                        <strong>材料如何保存</strong>
                        <p>材料存放在 Web 根目录之外的私有目录，仅平台审核人员可读取；不会出现在纪念馆、媒体库或任何公开链接中。审核完成后仍保留用于争议复核，你可以在账号设置中申请删除。</p>
                    </div>

                    <div class="manage-actions-row">
                        <button class="button" type="submit">提交核验</button>
                        <a class="text-link" href="<?= e(url($base)) ?>">返回概况</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="manage-section">
            <h2>为什么要核验</h2>
            <p>网络祭奠平台曾出现未审核逝者信息、活人被恶意创建纪念馆并被传播多年的案例。平台也可能因未尽审核义务承担侵权责任。</p>
            <ul class="manage-list">
                <li>新馆默认私密，只有馆主、协作者与受邀亲友可以访问。</li>
                <li>完成核验后，才可选择公开或不公开收录；公开页会显示“身份材料已核验”标记。</li>
                <li>任何访客都可以举报“活人被祭奠”或“虚假逝者信息”，平台会优先处理并暂停可疑纪念馆。</li>
                <li>核验不代表平台对生平内容作担保；如材料造假，纪念馆仍会被下架并保留追责权利。</li>
            </ul>
        </section>
    </div>
</div>
