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
                <h1><?= e($article['title']) ?></h1>
                <p class="muted" style="margin:0;">修改后可保存草稿，或重新提交发布。</p>
            </div>
            <?php if ($article['status'] === 'published'): ?>
                <a class="button button--small button--ghost" href="<?= e(url('/m/' . $memorial['slug'] . '/articles/' . (int) $article['id'])) ?>">查看公开文章</a>
            <?php endif; ?>
        </header>

        <?php if (!empty($article['review_note'])): ?>
            <div class="form-error" role="alert">审核说明：<?= e($article['review_note']) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <?php View::partial('manage/_article-form', [
                'memorial' => $memorial,
                'article' => $article,
                'old' => $old,
                'errors' => $errors,
                'isEdit' => true,
                'reviewRequired' => $reviewRequired,
                'formAction' => url($base . '/articles/' . (int) $article['id']),
            ]); ?>
        </section>

        <section class="manage-section">
            <h2>删除文章</h2>
            <p class="muted">删除后文章不再保留，操作不可恢复。</p>
            <form method="post" action="<?= e(url($base . '/articles/' . (int) $article['id'] . '/delete')) ?>" data-confirm="确认删除这篇文章？删除后不可恢复。">
                <?= csrf_field() ?>
                <button class="button button--small button--danger" type="submit">删除这篇文章</button>
            </form>
        </section>
    </div>
</div>
