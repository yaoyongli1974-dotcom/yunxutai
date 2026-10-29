<section class="error-page">
    <div class="shell error-page__inner" style="max-width:44rem;">
        <div class="error-page__glyph" aria-hidden="true">错</div>
        <p class="error-page__code"><?= e((string) $status) ?></p>
        <h1><?= e($title ?? '出错了') ?></h1>
        <p class="muted"><?= e($exception->getMessage()) ?></p>
        <pre class="debug-trace"><?= e($exception->getTraceAsString()) ?></pre>
        <p class="muted small" style="margin-top:1rem;">开发模式下的诊断信息，仅限本地环境显示。</p>
    </div>
</section>
