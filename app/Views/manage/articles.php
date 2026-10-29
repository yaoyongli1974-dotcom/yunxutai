<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'articles']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">纪念文章</p>
                <h1>把想说的话写下来</h1>
                <p class="muted" style="margin:0;">文章可以记录往事、家书或纪念日想说的话。草稿不会公开。</p>
            </div>
            <a class="button button--small button--ghost" href="<?= e(url('/m/' . $memorial['slug'] . '/articles')) ?>">查看公开文章</a>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>新建文章</h2>
            <?php View::partial('manage/_article-form', [
                'memorial' => $memorial,
                'article' => null,
                'old' => $old,
                'errors' => $errors,
                'isEdit' => false,
                'reviewRequired' => $reviewRequired,
                'formAction' => url($base . '/articles'),
            ]); ?>
        </section>

        <section class="manage-section">
            <h2>全部文章</h2>
            <?php if ($articles === []): ?>
                <p class="manage-empty">还没有文章。可以从一篇短的回忆开始。</p>
            <?php else: ?>
                <table class="manage-table">
                    <thead>
                    <tr>
                        <th>标题</th>
                        <th>状态</th>
                        <th>作者</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($articles as $article): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url($base . '/articles/' . (int) $article['id'])) ?>"><?= e($article['title']) ?></a>
                                <?php if (!empty($article['review_note'])): ?>
                                    <div class="manage-note manage-note--warn">审核说明：<?= e($article['review_note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="manage-badge manage-badge--<?= e((string) $article['status']) ?>"><?= e(status_label((string) $article['status'])) ?></span></td>
                            <td><?= e($article['author_name'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($article['published_at'])): ?>
                                    <?= e(format_datetime($article['published_at'])) ?>
                                <?php else: ?>
                                    <?= e(format_datetime($article['updated_at'] ?? $article['created_at'])) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="manage-table__actions">
                                    <a class="button button--small button--ghost" href="<?= e(url($base . '/articles/' . (int) $article['id'])) ?>">编辑</a>
                                    <form method="post" action="<?= e(url($base . '/articles/' . (int) $article['id'] . '/delete')) ?>" data-confirm="确认删除这篇文章？删除后不可恢复。">
                                        <?= csrf_field() ?>
                                        <button class="button button--small button--danger" type="submit">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
