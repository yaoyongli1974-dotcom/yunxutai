<?php
use App\Core\View;
?>
<section class="section">
    <div class="shell admin-shell">
        <?php View::partial('admin/_nav', ['active' => 'ai-jobs']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">AI 任务</p>
                    <h1>生成记录与失败重试</h1>
                    <p class="muted">用于核对成本、排查失败与协助馆主；不会自动公开任何生成内容。</p>
                </div>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/ai-services')) ?>">服务与价格</a>
            </div>

            <div class="admin-filters">
                <?php foreach (['' => '全部', 'queued' => '排队', 'failed' => '失败', 'completed' => '完成'] as $key => $label): ?>
                    <a class="button button--small <?= $status === $key ? '' : 'button--ghost' ?>" href="<?= e(url('/admin/ai-jobs' . ($key !== '' ? '?status=' . $key : ''))) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
                <span class="muted small">累计 <?= (int) $counts['all'] ?>，失败 <?= (int) $counts['failed'] ?></span>
            </div>

            <div class="admin-panel">
                <?php if ($jobs === []): ?>
                    <p class="admin-empty">暂无记录。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>#</th><th>纪念馆</th><th>服务</th><th>状态</th><th>模式</th><th>价格快照</th><th>时间</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td><?= (int) $job['id'] ?></td>
                                    <td>
                                        <a href="<?= e(url('/manage/memorials/' . (int) $job['memorial_id'])) ?>"><?= e((string) ($job['memorial_title'] ?? ('#' . (int) $job['memorial_id']))) ?></a>
                                        <br><span class="muted small"><?= e((string) ($job['user_name'] ?? '')) ?></span>
                                    </td>
                                    <td><?= e(ai_service_name((string) $job['service_slug'])) ?></td>
                                    <td>
                                        <span class="chip <?= $job['status'] === 'failed' ? 'chip--warn' : ($job['status'] === 'completed' ? 'chip--accent' : '') ?>"><?= e(ai_job_status_label((string) $job['status'])) ?></span>
                                        <?php if (!empty($job['error_message'])): ?>
                                            <br><span class="muted small"><?= e((string) $job['error_message']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="muted small"><?= e((string) $job['provider']) ?></td>
                                    <td><?= e(format_price((int) $job['cost_cents'])) ?></td>
                                    <td class="muted small"><?= e(format_datetime((string) $job['created_at'])) ?></td>
                                    <td>
                                        <?php if (in_array($job['status'], ['failed', 'queued'], true)): ?>
                                            <form method="post" action="<?= e(url('/admin/ai-jobs/' . (int) $job['id'] . '/retry')) ?>" data-confirm="重试会再次调用 AI 服务，确定继续？">
                                                <?= csrf_field() ?>
                                                <button class="button button--small" type="submit">重试</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php View::partial('admin/_pagination', [
                        'paginator' => $paginator,
                        'path' => '/admin/ai-jobs',
                        'query' => $status !== '' ? ['status' => $status] : [],
                    ]); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
