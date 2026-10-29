<?php
$active = $active ?? '';
$navItems = [
    'dashboard' => ['/admin', '运营概览'],
    'memorials' => ['/admin/memorials', '纪念馆'],
    'verifications' => ['/admin/verifications', '身份核验'],
    'moderation' => ['/admin/moderation', '内容审核'],
    'reports' => ['/admin/reports', '举报处理'],
    'claims' => ['/admin/claims', '申领管理'],
    'data-requests' => ['/admin/data-requests', '数据申请'],
    'services' => ['/admin/services', '服务申请'],
    'ai-services' => ['/admin/ai-services', 'AI 服务与价格'],
    'ai-jobs' => ['/admin/ai-jobs', 'AI 任务'],
    'users' => ['/admin/users', '用户'],
    'logs' => ['/admin/logs', '操作日志'],
];
?>
<aside class="admin-nav" aria-label="后台导航">
    <p class="eyebrow">平台运营</p>
    <nav>
        <?php foreach ($navItems as $key => [$path, $label]): ?>
            <a href="<?= e(url($path)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="admin-nav__note muted">
        所有处置均记录操作日志。涉及身份、授权与数据权益的事项，须先核验材料再处理。
    </p>
</aside>
