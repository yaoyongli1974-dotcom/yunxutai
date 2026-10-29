<?php
/**
 * 空状态：说明"为什么是空的"与"下一步做什么"。
 * @var string $title   标题
 * @var string $text    说明
 * @var string $glyph   可选：装饰字
 * @var array  $action   可选：['href' => url, 'label' => 文案, 'ghost' => bool]
 */
$glyph = $glyph ?? '空';
?>
<div class="empty">
    <div class="empty__glyph" aria-hidden="true"><?= e($glyph) ?></div>
    <h3><?= e($title) ?></h3>
    <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($action)): ?>
        <a class="button button--small<?= !empty($action['ghost']) ? ' button--ghost' : '' ?>" href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
    <?php endif; ?>
</div>
