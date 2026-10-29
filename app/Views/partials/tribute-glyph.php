<?php /** @var string $type 祭奠类型 */ ?>
<span class="trib-card__glyph" aria-hidden="true">
    <?= e(match ((string) $type) {
        'flower' => '花',
        'candle' => '烛',
        'incense' => '香',
        'bow' => '躬',
        default => '●',
    }) ?>
</span>
