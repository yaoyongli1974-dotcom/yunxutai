<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$status = (string) $job['status'];
$output = $job['output'];
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'ai']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">AI 任务 #<?= (int) $job['id'] ?></p>
                <h1><?= e($service['name'] ?? ai_service_name((string) $job['service_slug'])) ?></h1>
                <p class="muted" style="margin:0;"><?= e(format_datetime((string) $job['created_at'])) ?> · <?= e(ai_job_status_label($status)) ?> · <?= e($mode) ?></p>
            </div>
            <a class="button button--small button--ghost" href="<?= e(url($base . '/ai')) ?>">返回工作室</a>
        </header>

        <?php if ($status === 'failed'): ?>
            <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                <p><strong>这次生成没有完成。</strong></p>
                <p><?= e((string) ($job['error_message'] ?: '未知错误')) ?></p>
            </div>
            <form method="post" action="<?= e(url($base . '/ai/jobs/' . (int) $job['id'] . '/retry')) ?>" data-confirm="重新生成会再次调用 AI 服务，确定继续？">
                <?= csrf_field() ?>
                <button class="button" type="submit">重试这次任务</button>
            </form>
        <?php elseif ($status === 'queued' || $status === 'processing'): ?>
            <div class="notice-card">
                <p>任务正在队列中。若平台未启用自动处理，请联系运营运行 AI 队列。</p>
            </div>
        <?php else: ?>
            <div class="notice-card" style="margin-bottom:1.4rem;">
                <p><strong>请先核对，再使用。</strong> AI 可能写错姓名、时间或关系；涉及事实的内容必须由家人确认。结果默认仅成员可见。</p>
            </div>

            <?php if (($output['service'] ?? '') === 'photo_restore' && $media !== null): ?>
                <section class="manage-section">
                    <h2>修复结果</h2>
                    <figure class="ai-result">
                        <img src="<?= e(url('/media/' . (int) $media['id'])) ?>" alt="AI 辅助修复的照片">
                        <figcaption><?= e((string) ($media['caption'] ?? '')) ?></figcaption>
                    </figure>
                    <p class="muted small">
                        原图未被覆盖；成品默认仅成员可见，可在「影像与文件」中设为封面或下载。
                        如修复效果不理想，请重新生成并调整补充要求。
                    </p>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/media')) ?>">前往影像与文件</a>
                </section>
            <?php elseif (($output['service'] ?? '') === 'memorial_video' && $media !== null): ?>
                <section class="manage-section">
                    <h2>短片预览</h2>
                    <video class="ai-result-video" controls preload="metadata" src="<?= e(url('/media/' . (int) $media['id'])) ?>"></video>
                    <?php if (!empty($output['storyboard']) && is_array($output['storyboard'])): ?>
                        <h3>分镜文字</h3>
                        <ol class="ai-storyboard">
                            <?php foreach ($output['storyboard'] as $line): ?>
                                <li><?= e((string) $line) ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="muted small">短片为照片拼接，暂无配乐与配音；分镜文字可供家人整理旁白。</p>
                    <?php endif; ?>
                </section>
            <?php elseif (($output['service'] ?? '') === 'life_article' && $article !== null): ?>
                <section class="manage-section">
                    <h2>生平草稿</h2>
                    <p><strong><?= e((string) $article['title']) ?></strong></p>
                    <p class="muted small">作者：<?= e((string) ($article['author_name'] ?? '')) ?> · 状态：草稿</p>
                    <div class="ai-article-preview"><?= nl2br(e((string) $article['content'])) ?></div>
                    <a class="button" href="<?= e(url($base . '/articles/' . (int) $article['id'])) ?>">打开并核对草稿</a>
                </section>
            <?php elseif (($output['service'] ?? '') === 'family_archive' && $media !== null): ?>
                <section class="manage-section">
                    <h2>家族数字档案</h2>
                    <p>已生成一份可离线保存的 HTML 档案，包含生平、时间轴、纪念文章与照片缩略图。</p>
                    <p class="muted small">
                        收录：<?= (int) ($output['events'] ?? 0) ?> 条时间轴、
                        <?= (int) ($output['articles'] ?? 0) ?> 篇文章、
                        <?= (int) ($output['photos'] ?? 0) ?> 张照片。
                    </p>
                    <a class="button" href="<?= e(url('/media/' . (int) $media['id'])) ?>">下载档案</a>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <p class="muted small" style="margin-top:2rem;">
            服务价格：<?= e(format_price((int) ($service['price_cents'] ?? 0))) ?> / <?= e((string) ($service['unit'] ?? '次')) ?>。
            当前为运营占位价，内测期不实际扣费；平台不售卖虚拟祭品，也不按祭奠次数收费。
        </p>
    </div>
</div>
