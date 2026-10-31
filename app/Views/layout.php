<?php
$user = auth_user();
$flashMessages = App\Core\Flash::pull();
$pageTitle = page_title((string) ($title ?? ''));
$isNoindex = (bool) ($noindex ?? false);

$navCurrent = function (string $prefix): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return $path === $prefix || str_starts_with($path, $prefix . '/') || ($prefix !== '/' && str_starts_with($path, $prefix)) ? ' aria-current="page"' : '';
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($metaDescription ?? '云叙台——为逝去的亲人建立一座安静的纪念馆。记取来时路，安放未尽言。') ?>">
    <?php if ($isNoindex): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <?php if (!empty($canonical)): ?>
        <link rel="canonical" href="<?= e($canonical) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">跳到正文</a>

<header class="site-header">
    <div class="shell site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="seal seal--small" aria-hidden="true">思</span>
            <span>
                <span class="brand__name"><?= e((string) config('app.name')) ?></span>
                <span class="brand__tagline"><?= e((string) config('app.tagline')) ?></span>
            </span>
        </a>
        <nav class="site-nav" aria-label="主导航">
            <a href="<?= e(url('/memorials')) ?>"<?= $navCurrent('/memorials') ?>>纪念馆</a>
            <a href="<?= e(url('/services')) ?>"<?= $navCurrent('/services') ?>>纪念服务</a>
            <a href="<?= e(url('/about')) ?>"<?= $navCurrent('/about') ?>>关于</a>
            <?php if ($user !== null): ?>
                <span class="site-nav__user"><?= e($user['display_name']) ?></span>
                <a href="<?= e(url('/dashboard')) ?>"<?= $navCurrent('/dashboard') ?>>我的纪念馆</a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a href="<?= e(url('/admin')) ?>"<?= $navCurrent('/admin') ?>>管理后台</a>
                <?php endif; ?>
                <a href="<?= e(url('/account')) ?>"<?= $navCurrent('/account') ?>>账号设置</a>
                <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="link-button" type="submit">退出</button>
                </form>
            <?php else: ?>
                <a href="<?= e(url('/login')) ?>"<?= $navCurrent('/login') ?>>登录</a>
                <a class="button button--small" href="<?= e(url('/register')) ?>">创建纪念馆</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if ($flashMessages !== []): ?>
    <div class="shell flash-stack" role="status" aria-live="polite">
        <?php foreach ($flashMessages as $message): ?>
            <div class="flash flash--<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main id="main">
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="shell site-footer__inner">
        <div>
            <p class="site-footer__brand"><span class="seal seal--small" aria-hidden="true">思</span><?= e((string) config('app.name')) ?></p>
            <p class="muted small"><?= e((string) config('app.tagline')) ?></p>
        </div>
        <nav aria-label="页脚导航">
            <a href="<?= e(url('/about')) ?>">关于</a>
            <a href="<?= e(url('/privacy')) ?>">隐私与数据保护</a>
            <a href="<?= e(url('/terms')) ?>">服务条款</a>
            <a href="<?= e(url('/about')) ?>#reporting">投诉与举报</a>
        </nav>
    </div>
    <div class="shell site-footer__note">
        <p>平台尊重逝者与亲属的隐私。纪念馆内容由创建者与协作者维护，公开范围由馆主选择；对不实或违规内容，各纪念馆页面均设有投诉举报入口。平台不设香火排行榜，不销售虚拟祭品。</p>
    </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
