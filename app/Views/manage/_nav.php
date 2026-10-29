<?php
$navUser = auth_user();
$navCanEdit = App\Core\Policy::canEdit($memorial, $navUser);
$navCanManage = App\Core\Policy::canManage($memorial, $navUser);
$navCanModerate = App\Core\Policy::canModerate($memorial, $navUser);
$navBase = '/manage/memorials/' . (int) $memorial['id'];
$navItems = [
    ['key' => 'overview', 'label' => '概况', 'href' => $navBase],
];
if ($navCanEdit) {
    $navItems[] = ['key' => 'profile', 'label' => '纪念馆资料', 'href' => $navBase . '/profile'];
    $navItems[] = ['key' => 'life', 'label' => '生平', 'href' => $navBase . '/life'];
    $navItems[] = ['key' => 'articles', 'label' => '纪念文章', 'href' => $navBase . '/articles'];
    $navItems[] = ['key' => 'media', 'label' => '影像与文件', 'href' => $navBase . '/media'];
}
if ($navCanModerate) {
    $navItems[] = ['key' => 'moderation', 'label' => '内容审核', 'href' => $navBase . '/moderation'];
}
if ($navCanManage) {
    $navItems[] = ['key' => 'ai', 'label' => 'AI 工作室', 'href' => $navBase . '/ai'];
    $navItems[] = ['key' => 'verification', 'label' => '身份核验', 'href' => $navBase . '/verification'];
    $navItems[] = ['key' => 'members', 'label' => '成员与邀请', 'href' => $navBase . '/members'];
    $navItems[] = ['key' => 'settings', 'label' => '访问与留言', 'href' => $navBase . '/settings'];
}
$statusText = status_label((string) $memorial['status']);
$visibilityText = visibility_label((string) $memorial['visibility']);
?>
<aside class="manage-sidebar">
    <p class="eyebrow">纪念馆管理</p>
    <p class="manage-sidebar__title">
        <a href="<?= e(url($navBase)) ?>"><?= e($memorial['title']) ?></a>
    </p>
    <p class="manage-sidebar__meta">
        <span class="manage-badge manage-badge--<?= e((string) $memorial['status']) ?>"><?= e($statusText) ?></span>
        <span class="manage-badge"><?= e($visibilityText) ?></span>
    </p>
    <nav class="manage-nav" aria-label="纪念馆管理导航">
        <?php foreach ($navItems as $item): ?>
            <a href="<?= e(url($item['href'])) ?>"<?= ($active ?? '') === $item['key'] ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <p class="manage-public">
        <a href="<?= e(url('/m/' . $memorial['slug'])) ?>">查看公开页面</a><br>
        <span class="muted">/m/<?= e($memorial['slug']) ?></span>
    </p>
    <p class="manage-backlink">
        <a href="<?= e(url('/dashboard')) ?>">← 我的纪念馆</a>
        <span class="muted"> · 你的角色：<?= e(auth_user() !== null ? role_label((string) (App\Core\Policy::role($memorial, auth_user()) ?? 'viewer')) : '访客') ?></span>
    </p>
</aside>
