<?php
$mediaKinds = ['photo' => '照片', 'video' => '视频', 'audio' => '音频', 'document' => '文档'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'moderation']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">审核队列</p>
                    <h1>内容审核</h1>
                    <p class="muted">审核待发布留言与文章，并复核影像资料的展示范围。</p>
                </div>
            </div>

            <?php if (!empty($errors['form'])): ?>
                <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
            <?php endif; ?>

            <div class="admin-note">
                审核应依据内容真实性、隐私与他人权益进行判断。通过后内容将对外展示；隐藏或驳回不会删除原始数据，处置均记录操作日志。
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <div>
                        <h2>待审留言</h2>
                        <p class="muted">共 <?= count($pendingMessages) ?> 条待处理（最多显示 50 条）。</p>
                    </div>
                </div>
                <?php if ($pendingMessages === []): ?>
                    <p class="admin-empty">暂无待审留言。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>留言内容</th><th>纪念馆</th><th>时间</th><th>审核</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendingMessages as $message): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($message['author_name']) ?></strong>
                                        <p><?= e(excerpt_text($message['content'], 160)) ?></p>
                                    </td>
                                    <td>
                                        <a href="<?= e(url('/admin/memorials/' . (int) $message['memorial_id'])) ?>"><?= e($message['full_name']) ?></a>
                                        <p class="muted">/m/<?= e($message['slug']) ?></p>
                                    </td>
                                    <td class="muted"><?= e(format_datetime($message['created_at'])) ?></td>
                                    <td>
                                        <form class="admin-actions" method="post" action="<?= e(url('/admin/moderation/messages/' . (int) $message['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="button button--small" type="submit" name="status" value="visible">通过并展示</button>
                                            <button class="button button--small button--ghost" type="submit" name="status" value="hidden">隐藏</button>
                                        </form>
                                        <p class="muted" style="font-size:0.76rem;margin:0.35rem 0 0;">通过即公开展示；请确认不涉及在世人员隐私或侵权内容。</p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <div>
                        <h2>待审文章</h2>
                        <p class="muted">共 <?= count($pendingArticles) ?> 篇待处理（最多显示 50 篇）。</p>
                    </div>
                </div>
                <?php if ($pendingArticles === []): ?>
                    <p class="admin-empty">暂无待审文章。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>文章</th><th>纪念馆</th><th>时间</th><th>审核</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendingArticles as $article): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($article['title']) ?></strong>
                                        <p class="muted"><?= e($article['author_name'] ?: '未署名') ?></p>
                                        <p><?= e(excerpt_text($article['content'], 160)) ?></p>
                                    </td>
                                    <td>
                                        <a href="<?= e(url('/admin/memorials/' . (int) $article['memorial_id'])) ?>"><?= e($article['full_name']) ?></a>
                                        <p class="muted">/m/<?= e($article['slug']) ?></p>
                                    </td>
                                    <td class="muted"><?= e(format_datetime($article['created_at'])) ?></td>
                                    <td>
                                        <form class="admin-inline-form" method="post" action="<?= e(url('/admin/moderation/articles/' . (int) $article['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <label class="field">
                                                <span>审核说明（驳回时建议填写）</span>
                                                <textarea name="review_note" maxlength="500" placeholder="最多 500 字"><?= e((int) ($old['id'] ?? 0) === (int) $article['id'] ? ($old['review_note'] ?? '') : '') ?></textarea>
                                            </label>
                                            <div class="admin-actions">
                                                <button class="button button--small" type="submit" name="action" value="approve">通过并发布</button>
                                                <button class="button button--small button--ghost" type="submit" name="action" value="reject">驳回</button>
                                            </div>
                                            <p class="muted" style="font-size:0.76rem;margin:0;">通过后文章将公开展示；驳回会保留审核说明。</p>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <div>
                        <h2>已隐藏媒体</h2>
                        <p class="muted">隐藏后前台与直链均不可访问，可在此复核恢复。</p>
                    </div>
                </div>
                <?php if ($hiddenMedia === []): ?>
                    <p class="admin-empty">暂无已隐藏媒体。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>文件</th><th>纪念馆</th><th>说明</th><th>状态</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($hiddenMedia as $media): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($media['original_name']) ?></strong>
                                        <p class="muted"><?= e($mediaKinds[$media['kind']] ?? $media['kind']) ?></p>
                                    </td>
                                    <td><a href="<?= e(url('/admin/memorials/' . (int) $media['memorial_id'])) ?>"><?= e($media['full_name']) ?></a></td>
                                    <td class="muted"><?= e($media['caption'] ?: '—') ?></td>
                                    <td><span class="admin-badge admin-badge--hidden">已隐藏</span></td>
                                    <td>
                                        <form method="post" action="<?= e(url('/admin/moderation/media/' . (int) $media['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="button button--small button--ghost" type="submit" name="action" value="show">确认恢复展示</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <div>
                        <h2>最近上传媒体</h2>
                        <p class="muted">最近 20 个展示中的媒体，可人工复核后隐藏。</p>
                    </div>
                </div>
                <?php if ($recentMedia === []): ?>
                    <p class="admin-empty">暂无媒体。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>文件</th><th>纪念馆</th><th>说明</th><th>时间</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentMedia as $media): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($media['original_name']) ?></strong>
                                        <p class="muted"><?= e($mediaKinds[$media['kind']] ?? $media['kind']) ?></p>
                                    </td>
                                    <td><a href="<?= e(url('/admin/memorials/' . (int) $media['memorial_id'])) ?>"><?= e($media['full_name']) ?></a></td>
                                    <td class="muted"><?= e($media['caption'] ?: '—') ?></td>
                                    <td class="muted"><?= e(format_datetime($media['created_at'])) ?></td>
                                    <td>
                                        <form method="post" action="<?= e(url('/admin/moderation/media/' . (int) $media['id'])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="button button--small button--ghost" type="submit" name="action" value="hide">隐藏</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="muted" style="font-size:0.8rem;margin:0.8rem 0 0;">平台不提供一键永久删除媒体；如需删除，应按数据申请流程核验授权后处理。</p>
            </div>
        </div>
    </div>
</section>
