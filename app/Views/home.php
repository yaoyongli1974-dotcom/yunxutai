<section class="hero">
    <div class="shell hero__inner">
        <p class="eyebrow">网络纪念 · 家族记忆</p>

        <h1 class="fade-rise">记取来时路，<br>安放未尽言。</h1>

        <p class="hero__lead fade-rise fade-rise--2">
            为逝去的亲人留一座安静的纪念馆。把生平片段、老照片和亲友的回忆，
            小心地整理在一起；在思念来时，献一束花、写几句话，都安静地放在那里，
            随时可以回来看看。
        </p>

        <div class="hero__actions fade-rise fade-rise--3">
            <?php if (auth_user() !== null): ?>
                <a class="button" href="<?= e(url('/manage/memorials/new')) ?>">为亲人创建纪念馆</a>
                <a class="button button--ghost" href="<?= e(url('/dashboard')) ?>">我的纪念馆</a>
            <?php else: ?>
                <a class="button" href="<?= e(url('/register')) ?>">免费创建纪念馆</a>
                <a class="button button--ghost button--quiet" href="<?= e(url('/memorials')) ?>">先看看别人的纪念馆</a>
            <?php endif; ?>
        </div>

        <p class="hero__note muted fade-rise fade-rise--4">免费建馆 · 自主选择公开范围 · 没有香火排行榜，也没有诱导消费</p>
    </div>
</section>

<section class="section">
    <div class="shell">
        <div class="section-heading">
            <p class="eyebrow">一种克制而长久的纪念</p>
            <h2>让记忆有处安放，让思念不用赶时间</h2>
            <p class="muted" style="max-width:40em;">忘记一个人的样子，往往是从遗漏一件小事开始的。我们提供的是存放这些小事的地方——不催促、不热闹、不比较。</p>
        </div>
        <div class="grid grid--3">
            <article class="card">
                <h3>生平与影像</h3>
                <p>把老人的生平、老照片、纪念文章整理成一份可以长期保存的数字档案，按时间记述，供后代查证与追想。</p>
            </article>
            <article class="card">
                <h3>亲友共同维护</h3>
                <p>馆主可以邀请亲属成为协作者，一起补充与校对资料。馆主、协作者、亲友各有各的权限，谁改了什么都有记录。</p>
            </article>
            <article class="card">
                <h3>庄重的祭奠</h3>
                <p>献花、点烛、敬香、鞠躬，附一句话或匿名，都是可以的。这里没有积分、没有排名，只保留一份安静的仪式。</p>
            </article>
        </div>

        <div class="notice-card" style="margin-top:2rem;">
            <p><strong>公开之前，先核验身份。</strong>新馆一律从私密开始；提交死亡证明或亲属关系材料、经平台人工核验后，才允许公开、被搜索引擎收录。未核验的纪念馆不会出现在列表里，也不能被随意传播。我们用这道门槛，避免“活人被祭拜”和恶意建馆。</p>
            <p class="muted" style="margin-bottom:0;"><a class="text-link" href="<?= e(url('/about#verification')) ?>">了解核验流程与材料保护</a></p>
        </div>
    </div>
</section>

<?php $summaryData = $summary ?? []; ?>
<?php if ($summaryData !== []): ?>
<section class="section section--tight" style="border-top:1px solid var(--line);">
    <div class="shell">
        <div class="grid grid--3">
            <?php if (!empty($summaryData['memorials'])): ?>
                <div class="card card--num">
                    <div class="card__num"><?= (int) $summaryData['memorials'] ?></div>
                    <p class="muted small">已创建的纪念馆</p>
                </div>
            <?php endif; ?>
            <?php if (isset($summaryData['tributes'])): ?>
                <div class="card card--num">
                    <div class="card__num"><?= (int) $summaryData['tributes'] ?></div>
                    <p class="muted small">亲友留下的心意</p>
                </div>
            <?php endif; ?>
            <?php if (isset($summaryData['messages'])): ?>
                <div class="card card--num">
                    <div class="card__num"><?= (int) $summaryData['messages'] ?></div>
                    <p class="muted small">写给亲人的留言</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (($memorials ?? []) !== []): ?>
<section class="section section--tint">
    <div class="shell">
        <div class="section-heading section-heading--row">
            <div>
                <p class="eyebrow">近期公开的纪念馆</p>
                <h2>被好好记住的人</h2>
            </div>
            <a class="text-link" href="<?= e(url('/memorials')) ?>">浏览全部</a>
        </div>
        <div class="grid grid--4">
            <?php foreach ($memorials as $memorial): ?>
                <?php App\Core\View::partial('partials/memorial-card', ['memorial' => $memorial]); ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section">
    <div class="shell split">
        <div>
            <p class="eyebrow">长期纪念服务</p>
            <h2>把整理与保存，交给认真的人</h2>
            <p class="muted">
                老照片修复、纪念视频制作、生平文字整理与家族数字档案。
                每一项都明码标价、按交付内容计费——我们不卖虚拟祭品，也不做任何消费排名。
            </p>
            <a class="button button--ghost" href="<?= e(url('/services')) ?>">了解这些服务</a>
        </div>
        <div class="notice-card">
            <h3>关于隐私与合规</h3>
            <p>私密纪念馆仅受邀成员可见，成员照片与附件同样经过服务端鉴权，别人拿不到链接也无济于事。</p>
            <p>要不要被搜索引擎收录，由馆主自己决定；未允许的不提供服务入口，也不再有别的。</p>
            <p>如发现纪念馆信息不实、被祭奠者仍在世或内容侵犯权益，每个纪念馆页面都有举报入口，平台会认真核查。</p>
            <p style="margin-top:0.9rem;"><a class="text-link" href="<?= e(url('/privacy')) ?>">阅读完整的隐私说明</a></p>
        </div>
    </div>
</section>
