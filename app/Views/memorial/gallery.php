<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>影像</span>
            </nav>
            <p class="eyebrow"><?= e((string) $memorial['full_name']) ?> 的影像</p>
            <h1>相册</h1>
            <p class="page-head__meta"><?= $photos !== [] ? '点击任意一张查看大图，共 ' . count($photos) . ' 张。' : '' ?>
                <?php if (!$isMember): ?>部分照片仅家人与受邀成员可见，这里的每一张都已获家属授权。<?php endif; ?>
            </p>
        </div>

        <?php if ($photos !== []): ?>
            <?php /* 点击展开的灯箱：:target 即灯箱，无需 JS。第一个灯箱够用，回退锚点用 #gallery-top */ ?>
            <div class="section--tight" id="gallery-top" style="padding-bottom:3rem;">
                <div class="gallery">
                    <?php foreach ($photos as $photo): ?>
                        <?php $memberOnly = $photo['visibility'] === 'members'; ?>
                        <a class="gallery__item" href="#ph-<?= (int) $photo['id'] ?>">
                            <img src="<?= e(url('/media/' . (int) $photo['id'])) ?>" alt="<?= e((string) $photo['caption'] ?: $memorial['full_name'] . '的照片') ?>" loading="lazy">
                            <?php if ($memberOnly): ?><span class="gallery__flag">家人可见</span><?php endif; ?>
                            <?php if ((string) $photo['caption'] !== ''): ?><span class="gallery__cap"><?= e($photo['caption']) ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($photos as $photo): ?>
                    <div class="lightbox" id="ph-<?= (int) $photo['id'] ?>">
                        <a class="lightbox__backdrop" href="#gallery-top" aria-label="关闭大图" tabindex="-1"></a>
                        <figure class="lightbox__frame">
                            <img src="<?= e(url('/media/' . (int) $photo['id'])) ?>" alt="<?= e((string) $photo['caption'] ?: $memorial['full_name'] . '的照片') ?>">
                            <figcaption>
                                <?= (string) $photo['caption'] !== '' ? e($photo['caption']) : '' ?>
                                <br><span style="color:#a89e8c;"><a href="#gallery-top" style="color:#a89e8c;">✕ 关闭</a></span>
                            </figcaption>
                            <a class="lightbox__close" href="#gallery-top" aria-label="关闭大图" style="position:absolute;top:0;right:0;">✕</a>
                        </figure>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="section--tight" id="gallery-top">
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '相册还是空的',
                    'text' => '老照片是纪念馆里最容易被惦记的部分。家人登录后可在管理后台的「影像」里上传与整理。',
                    'glyph' => '影',
                    'action' => $isMember
                        ? ['href' => url('/manage/memorials/' . (int) $memorial['id'] . '/media'), 'label' => '去上传照片']
                        : ['href' => url('/m/' . $memorial['slug']), 'label' => '返回纪念馆'],
                ]); ?>
            </div>
        <?php endif; ?>
    </div>
</article>
