<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>生平</span>
            </nav>
            <p class="eyebrow"><?= e((string) $memorial['full_name']) ?> 的生平</p>
            <h1>来时路</h1>
            <p class="page-head__meta">
                <?php if (life_dates($memorial) !== ''): ?><?= e(life_dates($memorial)) ?> · <?php endif; ?>由家人按时间整理，供后代查证与追想。
            </p>
        </div>

        <div class="section--tight" style="padding-bottom:3rem;">
            <?php if ($events !== []): ?>
                <ol class="timeline" style="max-width:44rem;">
                    <?php foreach ($events as $event): ?>
                        <li class="timeline__item">
                            <p class="timeline__date" style="font-size:0.95rem;">
                                <?= e((string) $event['year_label'] ?: (format_date((string) $event['event_date'], 'Y 年 n 月') ?: '时间待补充')) ?>
                            </p>
                            <h2 style="font-size:1.1rem;margin-bottom:0.3rem;"><?= e($event['title']) ?></h2>
                            <?php if ((string) $event['description'] !== ''): ?>
                                <p class="timeline__desc" style="font-size:0.95rem;"><?= nl2br(e($event['description'])) ?></p>
                            <?php endif; ?>
                            <?php if ((string) $event['location'] !== ''): ?>
                                <p class="timeline__place">地点：<?= e($event['location']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <hr class="divider">
                <?php if ((string) $memorial['bio'] !== ''): ?>
                    <div class="prose article-body" style="max-width:44rem;">
                        <?php foreach (preg_split('/\n{2,}/u', (string) $memorial['bio']) as $para): if (trim($para) === '') continue; ?>
                            <p><?= nl2br(e(trim($para))) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '生平大事记还在整理',
                    'text' => '家人会把出生、迁徙、工作与家庭的大事按时间补上。记得的每一件小事，也是生平的一部分。',
                    'glyph' => '史',
                    'action' => $isMember
                        ? ['href' => url('/manage/memorials/' . (int) $memorial['id'] . '/life'), 'label' => '去补充生平']
                        : ['href' => url('/m/' . $memorial['slug']), 'label' => '返回纪念馆'],
                ]); ?>
            <?php endif; ?>
        </div>
    </div>
</article>
