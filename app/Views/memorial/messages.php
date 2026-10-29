<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>留言</span>
            </nav>
            <p class="eyebrow"><?= e((string) $memorial['full_name']) ?> 的留言</p>
            <h1>想说的话</h1>
            <p class="page-head__meta">
                <?= $paginator->total > 0 ? '共 ' . (int) $paginator->total . ' 条留言，按时间排序。' : '' ?>
                每条留言旁都有举报入口，馆主可随时处理不当内容。
            </p>
        </div>

        <div class="section--tight" style="padding-bottom:3rem;">

            <?php if ($canPost): ?>
                <form class="form" method="post" action="<?= e(url('/m/' . $memorial['slug'] . '/messages')) ?>" style="max-width:40rem;margin-bottom:2.2rem;">
                    <?= csrf_field() ?>
                    <?php if ((int) $memorial['moderate_messages'] === 1): ?>
                        <p class="small muted" style="margin:0;">留言会先交给馆主确认，确认后公开展示。请写让家人看了安心的话。</p>
                    <?php endif; ?>
                    <label class="field">
                        <span>你的留言（2–1000 字，以 <?= e(auth_user()['display_name']) ?> 的名义发出）</span>
                        <textarea name="content" rows="4" minlength="2" maxlength="1000" required placeholder="称呼与想说的话。"></textarea>
                    </label>
                    <div class="form__actions">
                        <button class="button" type="submit">发布留言</button>
                        <span class="form__foot">也可以留下只言片语，不必写长。</span>
                    </div>
                </form>
            <?php elseif (auth_user() === null): ?>
                <p style="margin-bottom:2rem;">
                    <a class="button button--small" href="<?= e(url('/login?next=' . urlencode('/m/' . $memorial['slug'] . '/messages'))) ?>">登录后留言</a>
                    <span class="small muted" style="margin-left:0.6rem;">留言会署你的昵称，想象对方面对面听你说话。</span>
                </p>
            <?php else: ?>
                <p class="small muted" style="margin-bottom:2rem;">馆主未开放留言。若你是馆主，可在管理后台的设置里开启。</p>
            <?php endif; ?>

            <?php if ($messages !== []): ?>
                <div class="msg-list" style="max-width:40rem;">
                    <?php foreach ($messages as $message): ?>
                        <div class="msg-card">
                            <p class="msg-card__head">
                                <strong><?= e((string) $message['author_name']) ?></strong>
                                <span><?= e(format_datetime((string) $message['created_at'])) ?></span>
                            </p>
                            <p class="msg-card__content"><?= e($message['content']) ?></p>
                            <p class="msg-card__foot">
                                <a href="<?= e(url('/report?type=message&id=' . (int) $message['id'])) ?>">举报这条留言</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'base' => url('/m/' . $memorial['slug'] . '/messages'), 'query' => []]); ?>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有留言',
                    'text' => '第一条留言往往最难写。一句“家里都好”，也可以。',
                    'glyph' => '言',
                ]); ?>
            <?php endif; ?>
        </div>
    </div>
</article>
