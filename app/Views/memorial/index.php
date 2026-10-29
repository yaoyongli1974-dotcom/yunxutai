<section class="page-head">
    <div class="shell">
        <h1><?= e($query !== '' ? '“' . $query . '”的搜索结果' : '公开纪念馆') ?></h1>
        <?php if ($query !== ''): ?>
            <p class="page-head__meta">在逝者姓名、馆名与简介中查找。共找到 <?= (int) $paginator->total ?> 座。</p>
        <?php else: ?>
            <p class="page-head__meta">这些纪念馆由馆主选择公开，仅展示生平与影像。想拜访亲友的纪念馆，向家人要一个链接即可。</p>
        <?php endif; ?>
    </div>
</section>

<section class="section--tight" style="padding-bottom:2.6rem;">
    <div class="shell">
        <form class="search-row" method="get" action="<?= e(url('/memorials')) ?>" role="search" aria-label="搜索纪念馆">
            <label class="field search-row__bar" for="q">
                <span class="visually-hidden">搜索纪念馆</span>
                <input id="q" type="search" name="q" value="<?= e($query) ?>" placeholder="按逝者姓名、馆名或简介搜索" maxlength="60">
            </label>
            <button class="button" type="submit">搜索</button>
        </form>
        <?php if ($query !== ''): ?>
            <p class="small shell" style="margin-top:0.7rem;"><a href="<?= e(url('/memorials')) ?>">清除搜索，回到全部</a></p>
        <?php endif; ?>
    </div>
</section>

<section class="section section--tight" style="padding-top:0;">
    <div class="shell">
        <?php if ($memorials !== []): ?>
            <div class="grid grid--4">
                <?php foreach ($memorials as $memorial): ?>
                    <?php App\Core\View::partial('partials/memorial-card', ['memorial' => $memorial]); ?>
                <?php endforeach; ?>
            </div>
            <?php App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'base' => url('/memorials'), 'query' => $query !== '' ? ['q' => $query] : []]); ?>
        <?php else: ?>
            <?php if ($query !== ''): ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '没有找到匹配的纪念馆',
                    'text' => '换个更简短的词试试。许多纪念馆并未在搜索中收录，需要家人提供链接才能访问。',
                    'glyph' => '觅',
                    'action' => ['href' => url('/memorials'), 'label' => '浏览全部公开纪念馆', 'ghost' => true],
                ]); ?>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有公开的纪念馆',
                    'text' => '最早的馆可能还在整理中。你也可以为逝去的亲人建立第一座。',
                    'glyph' => '园',
                    'action' => ['href' => url('/register'), 'label' => '创建纪念馆'],
                ]); ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
