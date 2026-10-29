<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'] . '/articles')) ?>">纪念文章</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span><?= e($article['title']) ?></span>
            </nav>
        </div>

        <?php if ($article['status'] !== 'published' && $isMember): ?>
            <div class="banner banner--draft" style="margin-top:1rem;">
                <span class="banner__glyph" aria-hidden="true">审</span>
                <p>这篇<?= e(status_label((string) $article['status'])) ?>文章仅编辑与审核者可见，公开前会先经过确认。</p>
            </div>
        <?php endif; ?>

        <header class="section--tight" style="padding-top:1rem;">
            <div style="max-width:44rem;">
                <p class="eyebrow">纪念文章</p>
                <h1><?= e($article['title']) ?></h1>
                <p class="page-head__meta">
                    <?= $article['author_name'] !== null ? '作者·' . e($article['author_name']) : '' ?><?= $article['author_name'] !== null ? ' · ' : '' ?><?= e(format_date((string) ($article['published_at'] ?? $article['created_at']), 'Y 年 n 月 j 日')) ?>
                </p>
            </div>
        </header>

        <div class="section--tight" style="padding-bottom:3.5rem;">
            <div class="prose article-body" style="max-width:44rem;">
                <?php /* 先转义再分段，杜绝注入 */ ?>
                <?php foreach (preg_split('/\n{2,}/u', (string) $article['content']) as $para): if (trim($para) === '') continue; ?>
                    <p><?= nl2br(e(trim($para))) ?></p>
                <?php endforeach; ?>
            </div>

            <hr class="divider" style="max-width:44rem;">
            <p class="small muted" style="max-width:44rem;">
                <a href="<?= e(url('/m/' . $memorial['slug'] . '/articles')) ?>">← 回到全部文章</a>
                <span aria-hidden="true"> · </span>
                <a href="<?= e(url('/report?type=article&id=' . (int) $article['id'])) ?>">举报这篇文章</a>
            </p>
        </div>
    </div>
</article>
