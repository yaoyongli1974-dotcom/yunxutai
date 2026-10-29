<?php
/**
 * 分页：保留查询参数。
 * 用法：View::partial('partials/pagination', [
 *     'paginator' => $paginator,
 *     'base' => '/memorials',        // 不带查询串的路径
 *     'query' => ['q' => $query],    // 需要保留的其它查询参数
 * ]);
 * @var App\Core\Paginator $paginator
 */
$base = (string) ($base ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$query = $query ?? [];
$build = static function (int $page) use ($base, $query): string {
    $params = $query;
    $params['page'] = $page;
    return e($base . '?' . http_build_query($params));
};
?>
<?php if ($paginator->pages > 1): ?>
<nav class="pager" aria-label="分页">
    <a href="<?= $build($paginator->prevPage()) ?>"<?= $paginator->hasPrev() ? '' : ' aria-disabled="true"' ?> rel="prev">‹ 上一页</a>
    <span class="pager__num">第 <?= (int) $paginator->page ?> 页 · 共 <?= (int) $paginator->pages ?> 页 · <?= (int) $paginator->total ?> 条</span>
    <a href="<?= $build($paginator->nextPage()) ?>"<?= $paginator->hasNext() ? '' : ' aria-disabled="true"' ?> rel="next">下一页 ›</a>
</nav>
<?php endif; ?>
