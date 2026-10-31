<?php
$active = $active ?? '';
$navGroups = [
    '运营概览' => [
        'dashboard' => ['/admin', '运营概览'],
    ],
    '内容与合规' => [
        'memorials' => ['/admin/memorials', '纪念馆'],
        'verifications' => ['/admin/verifications', '身份核验'],
        'moderation' => ['/admin/moderation', '内容审核'],
        'reports' => ['/admin/reports', '举报处理'],
        'claims' => ['/admin/claims', '申领管理'],
    ],
    '用户与申请' => [
        'users' => ['/admin/users', '用户'],
        'data-requests' => ['/admin/data-requests', '数据申请'],
        'services' => ['/admin/services', '服务申请'],
    ],
    'AI 运营' => [
        'ai-services' => ['/admin/ai-services', 'AI 服务与价格'],
        'ai-settings' => ['/admin/ai-settings', 'AI 大模型设置'],
        'ai-orders' => ['/admin/ai-orders', 'AI 订单'],
        'ai-jobs' => ['/admin/ai-jobs', 'AI 任务'],
    ],
    '系统' => [
        'logs' => ['/admin/logs', '操作日志'],
    ],
];
?>
<aside class="admin-nav" aria-label="后台导航">
    <p class="eyebrow">平台运营</p>
    <?php foreach ($navGroups as $groupLabel => $items): ?>
        <?php $groupActive = array_key_exists($active, $items); ?>
        <details class="admin-nav__group"<?= $groupActive ? ' open' : '' ?>>
            <summary><?= e($groupLabel) ?></summary>
            <nav>
                <?php foreach ($items as $key => [$path, $label]): ?>
                    <a href="<?= e(url($path)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </details>
    <?php endforeach; ?>
    <p class="admin-nav__note muted">
        所有处置均记录操作日志。涉及身份、授权与数据权益的事项，须先核验材料再处理。
    </p>
</aside>
