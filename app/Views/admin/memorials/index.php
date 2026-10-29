<?php
$statusOptions = ['' => '全部状态', 'draft' => '草稿', 'active' => '展示中', 'suspended' => '已暂停'];
$visibilityOptions = ['' => '全部访问范围', 'public' => '公开', 'unlisted' => '不公开收录', 'private' => '私密'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'memorials']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">纪念馆</p>
                    <h1>纪念馆管理</h1>
                    <p class="muted">查看纪念馆状态与访问范围，处理违规或存在身份争议的纪念馆。</p>
                </div>
            </div>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/memorials')) ?>">
                <label class="field">
                    <span>搜索</span>
                    <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="80" placeholder="逝者姓名、馆名或链接">
                </label>
                <label class="field">
                    <span>状态</span>
                    <select name="status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>访问范围</span>
                    <select name="visibility">
                        <?php foreach ($visibilityOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $filters['visibility'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--small" type="submit">筛选</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/memorials')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>逝者 / 纪念馆</th>
                        <th>馆主</th>
                        <th>访问范围</th>
                        <th>核验</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($memorials === []): ?>
                        <tr><td colspan="7" class="muted">没有符合条件的纪念馆。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($memorials as $memorial): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('/admin/memorials/' . (int) $memorial['id'])) ?>"><strong><?= e($memorial['full_name']) ?></strong></a>
                                <p class="muted"><?= e($memorial['title']) ?> · /m/<?= e($memorial['slug']) ?></p>
                            </td>
                            <td><?= e($memorial['creator_name'] ?: '未知') ?></td>
                            <td><?= e(visibility_label($memorial['visibility'])) ?></td>
                            <td><span class="chip<?= $memorial['verification_status'] === 'verified' ? ' chip--accent' : ($memorial['verification_status'] === 'pending' ? '' : ' chip--warn') ?>"><?= e(verification_label((string) $memorial['verification_status'])) ?></span></td>
                            <td><span class="admin-badge admin-badge--<?= e($memorial['status']) ?>"><?= e(status_label($memorial['status'])) ?></span></td>
                            <td class="muted"><?= e(format_datetime($memorial['created_at'])) ?></td>
                            <td><a class="text-link" href="<?= e(url('/admin/memorials/' . (int) $memorial['id'])) ?>">查看详情</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/memorials',
                'query' => $query,
            ]); ?>
        </div>
    </div>
</section>
