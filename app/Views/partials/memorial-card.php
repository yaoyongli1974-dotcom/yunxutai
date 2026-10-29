<?php
/** @var array $memorial */
$coverUrl = !empty($memorial['cover_media_id']) ? url('/media/' . (int) $memorial['cover_media_id']) : '';
$name = (string) ($memorial['full_name'] ?? '');
?>
<a class="memorial-card" href="<?= e(url('/m/' . $memorial['slug'])) ?>">
    <div class="memorial-card__media">
        <?php if ($coverUrl !== ''): ?>
            <img src="<?= e($coverUrl) ?>" alt="<?= e($name) ?>的照片" loading="lazy">
        <?php else: ?>
            <span class="memorial-card__initial" aria-hidden="true"><?= e(mb_substr($name, 0, 1)) ?></span>
        <?php endif; ?>
    </div>
    <div class="memorial-card__body">
        <h3><?= e($name) ?></h3>
        <p class="memorial-card__dates"><?= e(life_dates($memorial)) ?></p>
        <?php $summary = (string) ($memorial['summary'] ?? ''); ?>
        <?php if ($summary !== ''): ?>
            <p class="memorial-card__summary"><?= e(excerpt_text($summary, 70)) ?></p>
        <?php endif; ?>
    </div>
</a>
