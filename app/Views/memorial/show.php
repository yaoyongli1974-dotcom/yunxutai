<article>
    <?php
    $status = (string) $memorial['status'];
    $visibility = (string) $memorial['visibility'];
    $isPaused = $status === 'suspended';
    $isDraft = $status === 'draft';
    ?>

    <?php if ($isPaused): ?>
        <div class="shell" style="padding-top:1.2rem;">
            <div class="banner banner--suspended">
                <span class="banner__glyph" aria-hidden="true">停</span>
                <p>该纪念馆已被暂停展示<?= $memorial['suspended_reason'] !== null && $memorial['suspended_reason'] !== '' ? '：' . e($memorial['suspended_reason']) : '' ?>。你现在能看到它，是因为你是这个馆的成员。</p>
            </div>
        </div>
    <?php elseif ($isDraft): ?>
        <div class="shell" style="padding-top:1.2rem;">
            <div class="banner banner--draft">
                <span class="banner__glyph" aria-hidden="true">稿</span>
                <p>纪念馆仍在整理与校对中，尚未对访客开放。成员可以先浏览，其余内容会随开放一起展示。</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($visibility === 'private'): ?>
        <div class="shell" style="padding-top:<?= $status !== 'active' ? '0.6' : '1.2' ?>rem;">
            <div class="banner banner--veil">
                <span class="banner__glyph" aria-hidden="true">密</span>
                <p>这是一座私密纪念馆，只对受邀的家人开放。照片与文章不会出现在搜索引擎中，也请避免将链接转发给馆外的人。</p>
            </div>
        </div>
    <?php elseif ($visibility === 'unlisted'): ?>
        <div class="shell" style="padding-top:<?= $status !== 'active' ? '0.6' : '1.2' ?>rem;">
            <div class="banner">
                <span class="banner__glyph" aria-hidden="true">隐</span>
                <p>该纪念馆未被搜索引擎收录，只通过这条链接访问。可以放心地把链接交给亲友。</p>
            </div>
        </div>
    <?php endif; ?>

    <header class="mem-hero">
        <div class="shell">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/memorials')) ?>">纪念馆</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span><?= e($memorial['title']) ?></span>
            </nav>

            <div class="mem-hero__inner">
                <figure class="mem-hero__frame">
                    <?php if ($cover !== null): ?>
                        <img src="<?= e(url('/media/' . (int) $cover['id'])) ?>" alt="<?= e($memorial['full_name']) ?>的照片">
                    <?php else: ?>
                        <div class="mem-hero__placeholder">
                            <span class="memorial-card__initial" aria-hidden="true"><?= e(mb_substr((string) $memorial['full_name'], 0, 1)) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($cover !== null && (string) $cover['caption'] !== ''): ?>
                        <figcaption><?= e($cover['caption']) ?></figcaption>
                    <?php endif; ?>
                </figure>

                <div class="mem-hero__panel">
                    <h1><?= e((string) $memorial['full_name']) ?></h1>
                    <?php if (life_dates($memorial) !== ''): ?>
                        <p class="mem-hero__dates"><?= e(life_dates($memorial)) ?></p>
                    <?php endif; ?>
                    <?php if (($memorial['verification_status'] ?? 'none') === 'verified'): ?>
                        <p><span class="chip chip--accent">身份材料已核验</span></p>
                    <?php endif; ?>
                    <?php $place = trim((string) $memorial['birth_place'] . (($memorial['birth_place'] ?? '') && ($memorial['death_place'] ?? '') ? ' → ' : '') . (string) $memorial['death_place']); ?>
                    <?php if ($place !== ''): ?>
                        <p class="mem-hero__place"><?= e($place) ?></p>
                    <?php endif; ?>

                    <?php $intro = (string) $memorial['summary']; ?>
                    <?php if ($intro === ''): ?>
                        <?php $intro = excerpt_text((string) $memorial['bio'], 120); ?>
                    <?php endif; ?>
                    <?php if ($intro !== ''): ?>
                        <p class="mem-hero__summary"><?= e($intro) ?></p>
                    <?php endif; ?>

                    <?php $chips = []; ?>
                    <?php if ($isMember && $role !== null): ?>
                        <?php $chips[] = ['class' => 'chip--accent', 'label' => '你的角色：' . role_label($role)]; ?>
                    <?php endif; ?>
                    <?php if ($visibility === 'private'): $chips[] = ['class' => '', 'label' => '私密馆']; endif; ?>
                    <?php if ($visibility === 'unlisted'): $chips[] = ['class' => '', 'label' => '未收录']; endif; ?>
                    <?php if ($isDraft): $chips[] = ['class' => 'chip--warn', 'label' => '草稿']; endif; ?>
                    <?php if ($isPaused): $chips[] = ['class' => 'chip--warn', 'label' => '已暂停']; endif; ?>
                    <?php if ($chips !== []): ?>
                        <div class="mem-hero__chips">
                            <?php foreach ($chips as $chip): ?>
                                <span class="chip <?= $chip['class'] ?>"><?= e($chip['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canPost || !auth_user() || $canManage || $canEdit): ?>
                        <div class="mem-hero__actions">
                            <?php if ($canPost): ?>
                                <a class="button" href="#tributes">祭奠 TA</a>
                            <?php elseif (!auth_user()): ?>
                                <a class="button" href="<?= e(url('/login?next=' . urlencode('/m/' . $memorial['slug']))) ?>">登录后祭奠</a>
                            <?php endif; ?>
                            <?php if ($canManage): ?>
                                <a class="button button--small" href="<?= e(url('/manage/memorials/' . (int) $memorial['id'])) ?>">管理纪念馆</a>
                            <?php endif; ?>
                            <?php if ($canEdit): ?>
                                <a class="button button--small button--ghost" href="<?= e(url('/manage/memorials/' . (int) $memorial['id'] . '/profile')) ?>">编辑生平与资料</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="shell">
        <nav class="mem-tabs" aria-label="纪念馆栏目">
            <ul>
                <li><a href="#tributes" aria-current="page">祭奠</a></li>
                <li><a href="#messages">留言<span class="mem-tabs__count"><?= (int) $messageTotal ?></span></a></li>
                <li><a href="#gallery">影像<span class="mem-tabs__count"><?= (int) $counts['photos'] ?></span></a></li>
                <li><a href="#life">生平</a></li>
                <li><a href="#articles">文章<span class="mem-tabs__count"><?= (int) $counts['articles'] ?></span></a></li>
                <li><a href="<?= e(url('/m/' . $memorial['slug'] . '/tributes')) ?>">全部祭奠记录</a></li>
            </ul>
        </nav>
    </div>

    <?php /* 祭奠仪式 */ ?>
    <section class="section" id="tributes">
        <div class="shell">
            <div class="section-heading">
                <p class="eyebrow">寄托心意</p>
                <h2>在这里，安静地行一个礼</h2>
            </div>

            <?php if ($canPost): ?>
                <form class="ritual" method="post" action="<?= e(url('/m/' . $memorial['slug'] . '/tributes')) ?>">
                    <?= csrf_field() ?>
                    <p class="ritual__intro">点一个按钮，就是一次祭奠。仪式结束了，可以留下附言，也可以不留。</p>
                    <div class="ritual__row">
                        <button class="ritual-btn" type="submit" name="type" value="flower">
                            <span class="ritual-btn__glyph" aria-hidden="true">花</span>
                            <span class="ritual-btn__label">献花</span>
                        </button>
                        <button class="ritual-btn" type="submit" name="type" value="candle">
                            <span class="ritual-btn__glyph" aria-hidden="true">烛</span>
                            <span class="ritual-btn__label">点烛</span>
                        </button>
                        <button class="ritual-btn" type="submit" name="type" value="incense">
                            <span class="ritual-btn__glyph" aria-hidden="true">香</span>
                            <span class="ritual-btn__label">敬香</span>
                        </button>
                        <button class="ritual-btn" type="submit" name="type" value="bow">
                            <span class="ritual-btn__glyph" aria-hidden="true">躬</span>
                            <span class="ritual-btn__label">鞠躬</span>
                        </button>
                    </div>

                    <details class="ritual__toggle">
                        <summary>想写一句话随行（附言 · 可匿名）</summary>
                        <div class="ritual__extra">
                            <label class="field">
                                <span>附言（可留空，最多 200 字，如开启审核，馆主确认后展示）</span>
                                <textarea name="note" rows="3" maxlength="200" placeholder="想到什么，就写什么。"></textarea>
                            </label>
                            <label class="checkbox">
                                <input type="checkbox" name="anonymous" value="1">
                                <span>不留名字（向他人显示为“一位访客”）</span>
                            </label>
                        </div>
                    </details>
                </form>
            <?php else: ?>
                <div class="ritual">
                    <p class="ritual__intro">祭奠需要登录。难过的时候不用着急，点烛献花这些事，登录后一分钟就可以做完。</p>
                    <a class="button" href="<?= e(url('/login?next=' . urlencode('/m/' . $memorial['slug'] . '/'))) ?>">登录后祭奠</a>
                    <p class="ritual__foot">还没有账号？<a href="<?= e(url('/register')) ?>">创建一个</a>，请放心，注册只用一个邮箱。</p>
                </div>
            <?php endif; ?>

            <?php /* 安静的计数，不排名、不比较 */ ?>
            <p class="fact-line">
                <?php if ($tributeTotal > 0): ?>
                    自纪念馆开放以来，亲友已祭奠 <?= (int) $tributeTotal ?> 次：
                    献花 <strong><?= (int) ($tributeCounts['flower'] ?? 0) ?></strong> 次、
                    点烛 <strong><?= (int) ($tributeCounts['candle'] ?? 0) ?></strong> 次、
                    敬香 <strong><?= (int) ($tributeCounts['incense'] ?? 0) ?></strong> 次、
                    鞠躬 <strong><?= (int) ($tributeCounts['bow'] ?? 0) ?></strong> 次。
                <?php else: ?>
                    还没有人来祭奠。若你熟悉<?= e((string) $memorial['full_name']) ?>，愿意的话，可以先来行一个小礼。
                <?php endif; ?>
            </p>
        </div>
    </section>

    <?php /* 最近祭奠 */ ?>
    <section class="section section--tight" id="recent-tributes" style="padding-top:0;">
        <div class="shell">
            <div class="section-heading section-heading--row">
                <div>
                    <p class="eyebrow">最近的心意</p>
                    <h2>有人来过，有人在想</h2>
                </div>
                <a class="text-link" href="<?= e(url('/m/' . $memorial['slug'] . '/tributes')) ?>">全部祭奠记录</a>
            </div>

            <?php if ($tributes !== []): ?>
                <div class="trib-list">
                    <?php foreach ($tributes as $tribute): ?>
                        <div class="trib-card">
                            <?php App\Core\View::partial('partials/tribute-glyph', ['type' => (string) $tribute['type']]); ?>
                            <div class="trib-card__body">
                                <p class="trib-card__head">
                                    <strong><?= e((int) $tribute['is_anonymous'] === 1 ? '一位访客' : (string) ($tribute['display_name'] ?? '亲友')) ?></strong>
                                    <span><?= e(tribute_label((string) $tribute['type'])) ?></span>
                                    <span><?= e(format_date((string) $tribute['created_at'], 'Y年n月j日')) ?></span>
                                </p>
                                <?php if ((string) $tribute['note'] !== ''): ?>
                                    <p class="trib-card__note"><?= e($tribute['note']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有祭奠记录',
                    'text' => '这里会安静地记下每一次献花与鞠躬，不会催促谁，也不会排列谁。',
                    'glyph' => '祭',
                ]); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php /* 影像预览 */ ?>
    <section class="section section--tint" id="gallery">
        <div class="shell">
            <div class="section-heading section-heading--row">
                <div>
                    <p class="eyebrow">影像</p>
                    <h2>留住过的样子</h2>
                </div>
                <a class="text-link" href="<?= e(url('/m/' . $memorial['slug'] . '/gallery')) ?>">全部影像<?= $counts['photos'] > 0 ? '（' . (int) $counts['photos'] . ' 张）' : '' ?></a>
            </div>

            <?php if ($photos !== []): ?>
                <div class="gallery">
                    <?php foreach ($photos as $photo): ?>
                        <?php $memberOnly = $photo['visibility'] === 'members'; ?>
                        <a class="gallery__item" href="<?= e(url('/m/' . $memorial['slug'] . '/gallery#ph-' . (int) $photo['id'])) ?>">
                            <img src="<?= e(url('/media/' . (int) $photo['id'])) ?>" alt="<?= e((string) $photo['caption'] ?: $memorial['full_name'] . '的照片') ?>" loading="lazy">
                            <?php if ($memberOnly): ?><span class="gallery__flag">家人可见</span><?php endif; ?>
                            <?php if ((string) $photo['caption'] !== ''): ?><span class="gallery__cap"><?= e($photo['caption']) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有上传照片',
                    'text' => '老照片不怕扫得慢，就怕一直锁在抽屉里。家人登录后可以到管理页面上传。',
                    'glyph' => '影',
                ]); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php /* 生平 */ ?>
    <section class="section" id="life">
        <div class="shell split">
            <div>
                <div class="section-heading">
                    <p class="eyebrow">生平</p>
                    <h2>走过来时的路</h2>
                </div>
                <?php if ($lifeEvents !== []): ?>
                    <?php $lifePreview = array_slice($lifeEvents, 0, 5); ?>
                    <ol class="timeline" style="list-style:none;">
                        <?php foreach ($lifePreview as $event): ?>
                            <li class="timeline__item">
                                <p class="timeline__date">
                                    <?= e((string) $event['year_label'] ?: (format_date((string) $event['event_date'], 'Y.m.d') ?: '时间待补充')) ?>
                                </p>
                                <p class="timeline__title"><?= e($event['title']) ?></p>
                                <?php if ((string) $event['description'] !== ''): ?>
                                    <p class="timeline__desc"><?= e($event['description']) ?></p>
                                <?php endif; ?>
                                <?php if ((string) $event['location'] !== ''): ?>
                                    <p class="timeline__place"><?= e($event['location']) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if (count($lifeEvents) > 5): ?>
                        <p class="muted small">其余 <?= count($lifeEvents) - 5 ?> 段经历，收录在完整生平里。</p>
                    <?php endif; ?>
                    <a class="button button--small button--ghost" href="<?= e(url('/m/' . $memorial['slug'] . '/life')) ?>">查看完整生平</a>
                <?php else: ?>
                    <?php App\Core\View::partial('partials/empty-state', [
                        'title' => '生平大事记还在整理',
                        'text' => '家人会把出生、迁徙、工作与家庭的大事逐一补上。',
                        'glyph' => '史',
                    ]); ?>
                <?php endif; ?>
            </div>
            <?php if ((string) $memorial['bio'] !== ''): ?>
                <div>
                    <p class="eyebrow">他的生平</p>
                    <div class="prose">
                        <?php foreach (preg_split('/\n{2,}/u', (string) $memorial['bio']) as $bioParagraph): if (trim($bioParagraph) === '') continue; ?>
                            <p><?= nl2br(e(trim($bioParagraph))) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php /* 文章 */ ?>
    <section class="section section--tint" id="articles">
        <div class="shell">
            <div class="section-heading section-heading--row">
                <div>
                    <p class="eyebrow">纪念文章</p>
                    <h2>写下来，是为了以后有人问起</h2>
                </div>
                <a class="text-link" href="<?= e(url('/m/' . $memorial['slug'] . '/articles')) ?>">全部文章</a>
            </div>

            <?php if ($articles !== []): ?>
                <div class="grid grid--3">
                    <?php foreach (array_slice($articles, 0, 3) as $article): ?>
                        <a class="article-teaser" href="<?= e(url('/m/' . $memorial['slug'] . '/articles/' . (int) $article['id'])) ?>">
                            <h3><?= e($article['title']) ?></h3>
                            <p><?= e(excerpt_text($article['content'], 68)) ?></p>
                            <p class="article-teaser__meta">
                                <?= $article['author_name'] !== null ? e($article['author_name']) : '' ?><?= $article['author_name'] !== null ? ' · ' : '' ?><?= e(format_date((string) ($article['published_at'] ?? ''), 'Y年n月')) ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有纪念文章',
                    'text' => '白事之后的日子还长，文章不怕写长也不怕写短。发表后经过馆主确认即可展示。',
                    'glyph' => '文',
                ]); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php /* 留言 */ ?>
    <section class="section" id="messages">
        <div class="shell">
            <div class="section-heading section-heading--row">
                <div>
                    <p class="eyebrow">留言</p>
                    <h2>想对他说的话</h2>
                </div>
                <a class="text-link" href="<?= e(url('/m/' . $memorial['slug'] . '/messages')) ?>">全部留言<?= $messageTotal > 0 ? '（' . (int) $messageTotal . ' 条）' : '' ?></a>
            </div>

            <?php if ($canPost && $allowGuestbook): ?>
                <form class="form form--quiet" method="post" action="<?= e(url('/m/' . $memorial['slug'] . '/messages')) ?>" style="max-width:38rem;margin-bottom:1.8rem;">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>写一条留言<?= (int) $memorial['moderate_messages'] === 1 ? '（经馆主确认后展示）' : '' ?></span>
                        <textarea name="content" rows="3" minlength="2" maxlength="1000" required placeholder="称呼与想说的话。留言面向所有能访问这个馆的人。"></textarea>
                    </label>
                    <div class="form__actions">
                        <button class="button button--small" type="submit">发布留言</button>
                        <span class="form__foot">留言会记录发出时间，请放过逝者也放过别人。</span>
                    </div>
                </form>
            <?php elseif (auth_user() === null && $allowGuestbook): ?>
                <p class="form__foot" style="margin-bottom:1.6rem;">
                    <a class="button button--small button--ghost" href="<?= e(url('/login?next=' . urlencode('/m/' . $memorial['slug'] . '/messages'))) ?>">登录后留言</a>
                    <span style="margin-left:0.6rem;">留言面向前来缅怀的人，匿名与否你说了算。</span>
                </p>
            <?php elseif (!$allowGuestbook): ?>
                <p class="muted small" style="margin-bottom:1.6rem;">本馆保留了留言功能之外的清净，馆主未开放留言。</p>
            <?php endif; ?>

            <?php if ($messages !== []): ?>
                <div class="msg-list">
                    <?php foreach ($messages as $message): ?>
                        <div class="msg-card">
                            <p class="msg-card__head">
                                <strong><?= e((string) $message['author_name']) ?></strong>
                                <span><?= e(format_date((string) $message['created_at'], 'Y年n月j日')) ?></span>
                            </p>
                            <p class="msg-card__content"><?= e($message['content']) ?></p>
                            <p class="msg-card__foot">
                                <a href="<?= e(url('/report?type=message&id=' . (int) $message['id'])) ?>">举报</a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => $allowGuestbook ? '还没有留言' : '留言暂未开放',
                    'text' => $messageTotal === 0 && $allowGuestbook
                        ? '第一条留言往往最难写。一句“爸，家里都好”，也可以。'
                        : '如需留言条目，请等馆主开放后再试。',
                    'glyph' => '言',
                    'action' => $allowGuestbook && $messageTotal === 0 && auth_user() !== null
                        ? ['href' => url('/m/' . $memorial['slug'] . '/messages'), 'label' => '去写一条', 'ghost' => false]
                        : [],
                ]); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php /* 分享 / 举报 / 成员入口 */ ?>
    <section class="section section--tight">
        <div class="shell" style="padding-top:1.8rem;border-top:1px solid var(--line);">
            <div class="split" style="align-items:flex-start;">
                <div>
                    <p class="eyebrow">分享与传承</p>
                    <h3 style="font-size:1.1rem;">把这个纪念馆分享给亲友</h3>
                    <div class="copy-row">
                        <label class="field">
                            <span class="visually-hidden">纪念馆链接</span>
                            <input type="text" data-copy-target readonly value="<?= e((string) ($canonical ?? url('/m/' . $memorial['slug']))) ?>">
                        </label>
                        <button class="button button--ghost" type="button" data-copy="<?= e((string) ($canonical ?? url('/m/' . $memorial['slug']))) ?>">复制链接</button>
                    </div>
                    <p class="share-note">私密馆与未收录馆不会被搜索引擎收录，请只分享给想让他们看到的人。</p>
                </div>
                <div>
                    <p class="eyebrow">发现问题</p>
                    <p class="small muted" style="margin-bottom:0.8rem;">如果这个纪念馆存在信息不实、被祭奠者仍在世、侵犯隐私或不当内容，这里提供直接的入口：</p>
                    <a class="button button--small button--ghost" href="<?= e(url('/report?type=memorial&id=' . (int) $memorial['id'])) ?>">投诉与举报</a>
                    <?php if (auth_user() !== null && !$isMember && $status === 'active'): ?>
                        <p class="small muted" style="margin-top:1rem;">你是<?= e((string) $memorial['full_name']) ?>的亲人？<a href="<?= e(url('/m/' . $memorial['slug'] . '/claim')) ?>">申请管理这个纪念馆</a>。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</article>
