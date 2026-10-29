<?php
$query = $query ?? [];
$pageLink = static function (int $page) use ($path, $query): string {
    $params = $query;
    if ($page > 1) {
        $params['page'] = $page;
    } else {
        unset($params['page']);
    }
    $suffix = $params !== [] ? '?' . http_build_query($params) : '';
    return url($path . $suffix);
};
?>
<nav class="admin-pagination" aria-label="分页">
    <span class="muted">共 <?= (int) $paginator->total ?> 条 · 第 <?= (int) $paginator->page ?> / <?= (int) $paginator->pages ?> 页</span>
    <span class="admin-pagination__links">
        <?php if ($paginator->hasPrev()): ?>
            <a class="button button--small button--ghost" href="<?= e($pageLink($paginator->prevPage())) ?>" rel="prev">上一页</a>
        <?php else: ?>
            <span class="button button--small button--ghost" aria-disabled="true">上一页</span>
        <?php endif; ?>
        <?php if ($paginator->hasNext()): ?>
            <a class="button button--small button--ghost" href="<?= e($pageLink($paginator->nextPage())) ?>" rel="next">下一页</a>
        <?php else: ?>
            <span class="button button--small button--ghost" aria-disabled="true">下一页</span>
        <?php endif; ?>
    </span>
</nav>
