<?php
use App\Core\View;

$editing = $editing ?? null;
$old = $old ?? [];
$errors = $errors ?? [];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php View::partial('admin/_nav', ['active' => 'ai-services']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">AI 自助服务</p>
                    <h1>服务与占位价格</h1>
                    <p class="muted">价格仅用于展示与运营记录，当前不接入支付。修改后立即在纪念馆 AI 工作室与服务页生效。</p>
                </div>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/ai-jobs')) ?>">查看 AI 任务</a>
            </div>

            <div class="admin-stats">
                <div class="admin-stat"><strong><?= (int) $jobsTotal ?></strong><span>累计 AI 任务</span></div>
                <div class="admin-stat"><strong><?= (int) $jobsFailed ?></strong><span>失败任务</span></div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <div>
                        <h2>四项自助服务</h2>
                        <p class="muted">占位价可按运营策略调整；基础纪念功能始终免费。</p>
                    </div>
                </div>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>服务</th><th>当前价格</th><th>价格说明</th><th>状态</th><th>更新</th></tr></thead>
                        <tbody>
                        <?php foreach ($services as $service): ?>
                            <?php $isEditing = $editing !== null && (int) $editing['id'] === (int) $service['id']; ?>
                            <tr>
                                <td>
                                    <strong><?= e($service['name']) ?></strong><br>
                                    <span class="muted small"><?= e($service['slug']) ?> · /<?= e($service['unit']) ?></span>
                                </td>
                                <td><?= e(format_price((int) $service['price_cents'])) ?></td>
                                <td class="muted small"><?= e((string) ($service['price_note'] ?? '')) ?></td>
                                <td><span class="chip <?= (int) $service['enabled'] ? 'chip--accent' : 'chip--warn' ?>"><?= (int) $service['enabled'] ? '开放' : '关闭' ?></span></td>
                                <td>
                                    <form class="form admin-inline-form" method="post" action="<?= e(url('/admin/ai-services/' . (int) $service['id'])) ?>">
                                        <?= csrf_field() ?>
                                        <label class="field">
                                            <span>价格（元）</span>
                                            <input type="number" name="price_yuan" step="0.01" min="0" max="999999" required
                                                   value="<?= e($isEditing ? (string) ($old['price_yuan'] ?? '') : number_format((int) $service['price_cents'] / 100, 2, '.', '')) ?>">
                                            <?php if ($isEditing && !empty($errors['price_yuan'])): ?><small class="field__error"><?= e($errors['price_yuan']) ?></small><?php endif; ?>
                                        </label>
                                        <label class="field">
                                            <span>价格说明</span>
                                            <input type="text" name="price_note" maxlength="190"
                                                   value="<?= e($isEditing ? (string) ($old['price_note'] ?? '') : (string) ($service['price_note'] ?? '')) ?>">
                                            <?php if ($isEditing && !empty($errors['price_note'])): ?><small class="field__error"><?= e($errors['price_note']) ?></small><?php endif; ?>
                                        </label>
                                        <label class="checkbox">
                                            <input type="checkbox" name="enabled" value="1" <?= (int) $service['enabled'] ? 'checked' : '' ?>>
                                            <span>对馆主开放</span>
                                        </label>
                                        <button class="button button--small" type="submit">保存</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="notice-card" style="margin-top:1.4rem;">
                <p><strong>收费边界：</strong>不售卖虚拟祭品、会员等级或香火排名；AI 生成是辅助劳动，失败任务应允许重试或退款。正式收费前需补充订单、合同、发票与退款流程。</p>
            </div>
        </div>
    </div>
</section>
