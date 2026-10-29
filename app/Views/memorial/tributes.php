<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>祭奠记录</span>
            </nav>
            <p class="eyebrow"><?= e((string) $memorial['full_name']) ?> 的祭奠记录</p>
            <h1>每一次行礼，都记在这里</h1>
            <p class="page-head__meta">
                <?php if ($totals > 0): ?>
                    共 <?= (int) $totals ?> 次：
                    献花 <?= (int) ($counts['flower'] ?? 0) ?> ·
                    点烛 <?= (int) ($counts['candle'] ?? 0) ?> ·
                    敬香 <?= (int) ($counts['incense'] ?? 0) ?> ·
                    鞠躬 <?= (int) ($counts['bow'] ?? 0) ?>。
                    这是安静的事实记录，不设任何排行。
                <?php endif; ?>
            </p>
        </div>

        <div class="section--tight" style="padding-bottom:3rem;">
            <?php if ($tributes !== []): ?>
                <div class="trib-list" style="max-width:44rem;">
                    <?php foreach ($tributes as $tribute): ?>
                        <div class="trib-card">
                            <?php App\Core\View::partial('partials/tribute-glyph', ['type' => (string) $tribute['type']]); ?>
                            <div class="trib-card__body">
                                <p class="trib-card__head">
                                    <strong><?= e((int) $tribute['is_anonymous'] === 1 ? '一位访客' : (string) ($tribute['display_name'] ?? '亲友')) ?></strong>
                                    <span><?= e(tribute_label((string) $tribute['type'])) ?></span>
                                    <span><?= e(format_datetime((string) $tribute['created_at'])) ?></span>
                                </p>
                                <?php if ((string) $tribute['note'] !== ''): ?>
                                    <p class="trib-card__note"><?= e($tribute['note']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'base' => url('/m/' . $memorial['slug'] . '/tributes'), 'query' => []]); ?>
                <p class="small" style="margin-top:1.4rem;">
                    <a class="button button--small" href="<?= e(url('/m/' . $memorial['slug'] . '#tributes')) ?>">去献一束花</a>
                </p>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有任何祭奠记录',
                    'text' => '记录会是空的，直到有人来。登录后即可献花、点烛或写一句话。',
                    'glyph' => '祭',
                    'action' => auth_user() === null
                        ? ['href' => url('/login?next=' . urlencode('/m/' . $memorial['slug'] . '/tributes')), 'label' => '登录后祭奠']
                        : ['href' => url('/m/' . $memorial['slug'] . '#tributes'), 'label' => '去祭奠'],
                ]); ?>
            <?php endif; ?>
        </div>
    </div>
</article>
