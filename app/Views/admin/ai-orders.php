<?php
/**
 * 管理后台 · AI 收款订单。
 *
 * 来源：$orders（包含 user_email/user_name/memorial_slug/memorial_name）与 $status 过滤。
 * 操作：待确认订单可「确认收款」（payment_ref 选填）或「驳回」（note 必填）。
 */

use App\Core\Csrf;
use App\Models\AiJob;

/** @var array $orders */
/** @var string $status */
const AI_ORDER_STATUS_LABELS = [
    'pending' => '待确认',
    'paid' => '已生成任务',
    'rejected' => '已驳回',
    'cancelled' => '已取消',
];
$filters = [
    '' => '全部',
    'pending' => '待确认',
    'paid' => '已生成任务',
    'rejected' => '已驳回',
];
?>
<section class="admin-section">
    <p class="eyebrow">AI 付费订单</p>
    <h2>收款确认与驳回</h2>
    <p class="muted small">订单审核制：馆主提交后平台人工核价并确认收款，确认后自动生成 AI 任务（不占每日免费额度）。驳回需填写说明，馆内会看到处理结果。</p>

    <nav class="admin-tabs" aria-label="订单过滤">
        <?php foreach ($filters as $key => $label): ?>
            <a href="/admin/ai-orders<?= $key === '' ? '' : '?status=' . e($key) ?>"<?= $status === $key ? ' class="is-active"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($orders === []): ?>
        <div class="empty-state">
            <h3>暂无订单</h3>
            <p class="muted">开启「收款后生成」模式后，馆主提交 AI 任务时会出现待确认订单。</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>服务</th>
                        <th>价格</th>
                        <th>状态</th>
                        <th>用户</th>
                        <th>馆</th>
                        <th>时间</th>
                        <th>处理</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $label = AI_ORDER_STATUS_LABELS[(string) $order['status']] ?? (string) $order['status']; ?>
                        <tr>
                            <td><?= (int) $order['id'] ?></td>
                            <td>
                                <strong><?= e((string) $order['service_name']) ?></strong>
                                <div class="muted small"><?= e((string) $order['service_slug']) ?></div>
                            </td>
                            <td><?= e(AiJob::yuan((int) $order['price_cents'])) ?></td>
                            <td><span class="tag tag--<?= e((string) $order['status']) ?>"><?= e($label) ?></span></td>
                            <td>
                                <div><?= e((string) ($order['user_name'] ?? '')) ?></div>
                                <div class="muted small"><?= e((string) ($order['user_email'] ?? '')) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($order['memorial_slug'])): ?>
                                    <a href="/m/<?= e((string) $order['memorial_slug']) ?>"><?= e((string) ($order['memorial_name'] ?? '')) ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted small"><?= e((string) $order['created_at']) ?></td>
                            <td>
                                <?php if ((string) $order['status'] === 'pending'): ?>
                                    <form method="post" action="/admin/ai-orders/<?= (int) $order['id'] ?>/confirm" class="inline-form">
                                        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                                        <input type="text" name="payment_ref" value="" placeholder="付款参考号（选填）" maxlength="190" class="input-sm">
                                        <button type="submit" class="btn btn--primary btn--sm">确认收款</button>
                                    </form>
                                    <form method="post" action="/admin/ai-orders/<?= (int) $order['id'] ?>/reject" class="inline-form">
                                        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                                        <textarea name="note" rows="2" placeholder="驳回说明（必填，≤500字）" class="input-sm"></textarea>
                                        <button type="submit" class="btn btn--sm btn--ghost">驳回</button>
                                    </form>
                                <?php elseif ((string) $order['status'] === 'paid' && !empty($order['task_id'])): ?>
                                    <a href="/admin/ai-jobs">任务 #<?= (int) $order['task_id'] ?></a>
                                    <?php if (!empty($order['payment_ref'])): ?>
                                        <div class="muted small">参考号：<?= e((string) $order['payment_ref']) ?></div>
                                    <?php endif; ?>
                                <?php elseif ((string) $order['status'] === 'rejected'): ?>
                                    <div class="muted small"><?= e((string) ($order['note'] ?? '')) ?></div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p class="admin-note muted small">合规提示：订单确认即记账并生成任务；未确认前不扣费、不生成内容。请在隐私政策与服务条款中保持「收款后生成 / 未确认可全额取消」的一致表述。</p>
</section>
