<section class="error-page">
    <div class="shell error-page__inner">
        <div class="error-page__glyph" aria-hidden="true">缺</div>
        <p class="error-page__code"><?= e((string) $status) ?></p>
        <h1><?= e($title ?? '暂时无法访问') ?></h1>
        <p class="muted"><?= e($message ?? '这里空空如也。可能页面已被移除，或链接不完整。') ?></p>
        <div class="error-page__actions">
            <a class="button" href="<?= e(url('/')) ?>">回到首页</a>
            <a class="button button--ghost" href="<?= e(url('/memorials')) ?>">浏览纪念馆</a>
        </div>
        <p class="small muted" style="margin-top:1.6rem;">若是私密纪念馆，请向馆内的家人索要链接；他们会重新邀请你。</p>
    </div>
</section>
