<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>纪念文章</span>
            </nav>
            <p class="eyebrow"><?= e((string) $memorial['full_name']) ?> 的纪念文章</p>
            <h1>写下来的话</h1>
            <p class="page-head__meta">文章经馆主与平台确认后公开展示，涉及他人隐私的内容不会出现。</p>
        </div>

        <div class="section--tight" style="padding-bottom:3rem;max-width:44rem;">
            <?php if ($articles !== []): ?>
                <div class="article-list">
                    <?php foreach ($articles as $item): ?>
                        <a class="article-teaser" href="<?= e(url('/m/' . $memorial['slug'] . '/articles/' . (int) $item['id'])) ?>">
                            <h3><?= e($item['title']) ?></h3>
                            <p><?= e(excerpt_text($item['content'], 90)) ?></p>
                            <p class="article-teaser__meta">
                                <?= $item['author_name'] !== null ? e($item['author_name']) : '家人' ?> · <?= e(format_date((string) ($item['published_at'] ?? ''), 'Y 年 n 月')) ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有文章',
                    'text' => '关于他的文章还没有发表。哪天想写了，交给我们保管就好。',
                    'glyph' => '文',
                ]); ?>
            <?php endif; ?>
        </div>
    </div>
</article>
