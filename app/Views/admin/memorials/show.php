<?php
$reportTargets = ['memorial' => '纪念馆', 'message' => '留言', 'tribute' => '祭奠记录', 'media' => '影像', 'article' => '文章'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'memorials']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow"><a class="text-link" href="<?= e(url('/admin/memorials')) ?>">← 返回纪念馆列表</a></p>
                    <h1><?= e($memorial['title']) ?></h1>
                    <p class="muted">
                        逝者 <?= e($memorial['full_name']) ?>
                        <?php if (life_dates($memorial) !== ''): ?> · <?= e(life_dates($memorial)) ?><?php endif; ?>
                    </p>
                </div>
                <div class="admin-actions">
                    <span class="admin-badge admin-badge--<?= e($memorial['status']) ?>"><?= e(status_label($memorial['status'])) ?></span>
                    <?php if ($memorial['status'] === 'active'): ?>
                        <a class="text-link" href="<?= e(url('/m/' . $memorial['slug'])) ?>" target="_blank" rel="noopener">查看前台页面</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($memorial['status'] === 'suspended' && !empty($memorial['suspended_reason'])): ?>
                <div class="admin-note admin-note--warning">
                    <strong>已暂停展示。</strong>
                    理由：<?= e($memorial['suspended_reason']) ?>
                </div>
            <?php endif; ?>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <h2>基本信息</h2>
                    <p class="muted">创建于 <?= e(format_datetime($memorial['created_at'])) ?></p>
                </div>
                <dl class="admin-definition">
                    <div><dt>纪念馆链接</dt><dd>/m/<?= e($memorial['slug']) ?></dd></div>
                    <div><dt>访问范围</dt><dd><?= e(visibility_label($memorial['visibility'])) ?></dd></div>
                    <div><dt>身份核验</dt><dd><?= e(verification_label((string) $memorial['verification_status'])) ?><?php if (!empty($memorial['verified_at'])): ?> · <?= e(format_datetime($memorial['verified_at'])) ?><?php endif; ?></dd></div>
                    <div><dt>搜索引擎收录</dt><dd><?= (int) $memorial['allow_search_index'] === 1 ? '允许' : '不允许' ?></dd></div>
                    <div><dt>留言板</dt><dd><?= (int) $memorial['allow_guestbook'] === 1 ? '开放' : '关闭' ?></dd></div>
                    <div><dt>留言审核</dt><dd><?= (int) $memorial['moderate_messages'] === 1 ? '先审后发' : '直接展示' ?></dd></div>
                    <div><dt>创建者</dt><dd><?= e($creator['display_name'] ?? '未知') ?><?php if ($creator): ?>（<?= e($creator['email']) ?>）<?php endif; ?></dd></div>
                    <div><dt>发布时间</dt><dd><?= e(format_datetime($memorial['published_at']) ?: '尚未发布') ?></dd></div>
                    <div><dt>逝者生卒</dt><dd><?= e(format_date($memorial['birth_date']) ?: '未知') ?> — <?= e(format_date($memorial['death_date']) ?: '未知') ?></dd></div>
                    <div><dt>出生地</dt><dd><?= e($memorial['birth_place'] ?: '未填写') ?></dd></div>
                    <div><dt>安葬地</dt><dd><?= e($memorial['death_place'] ?: '未填写') ?></dd></div>
                </dl>
                <?php if (!empty($memorial['summary'])): ?>
                    <p class="muted" style="margin-top:1rem;"><?= e($memorial['summary']) ?></p>
                <?php endif; ?>
                <?php if (!empty($memorial['bio'])): ?>
                    <p style="margin-top:0.8rem;font-size:0.9rem;"><?= nl2br(e(excerpt_text($memorial['bio'], 800))) ?></p>
                <?php endif; ?>
            </div>

            <div class="admin-stats">
                <div class="admin-stat"><strong><?= (int) $counts['messages'] ?></strong><span>展示中留言</span></div>
                <div class="admin-stat"><strong><?= (int) $counts['articles'] ?></strong><span>已发布文章</span></div>
                <div class="admin-stat"><strong><?= (int) $counts['photos'] ?></strong><span>照片</span></div>
                <div class="admin-stat"><strong><?= (int) $counts['members'] ?></strong><span>有效成员</span></div>
                <div class="admin-stat"><strong><?= (int) $counts['tributes'] ?></strong><span>祭奠记录</span></div>
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <h2>最近留言</h2>
                        <a class="text-link" href="<?= e(url('/admin/moderation')) ?>">内容审核</a>
                    </div>
                    <?php if ($recentMessages === []): ?>
                        <p class="admin-empty">暂无留言。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>作者 / 内容</th><th>状态</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentMessages as $message): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($message['author_name']) ?></strong>
                                            <p class="muted"><?= e(excerpt_text($message['content'], 70)) ?></p>
                                        </td>
                                        <td><span class="admin-badge admin-badge--<?= e($message['status']) ?>"><?= e(status_label($message['status'])) ?></span></td>
                                        <td class="muted"><?= e(format_datetime($message['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <h2>最近文章</h2>
                        <a class="text-link" href="<?= e(url('/admin/moderation')) ?>">内容审核</a>
                    </div>
                    <?php if ($recentArticles === []): ?>
                        <p class="admin-empty">暂无文章。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>标题</th><th>状态</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentArticles as $article): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($article['title']) ?></strong>
                                            <p class="muted"><?= e($article['author_name'] ?: '未署名') ?></p>
                                        </td>
                                        <td><span class="admin-badge admin-badge--<?= e($article['status']) ?>"><?= e(status_label($article['status'])) ?></span></td>
                                        <td class="muted"><?= e(format_datetime($article['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <h2>相关举报</h2>
                    <a class="text-link" href="<?= e(url('/admin/reports')) ?>">举报列表</a>
                </div>
                <?php if ($recentReports === []): ?>
                    <p class="admin-empty">暂无相关举报。</p>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>ID</th><th>目标</th><th>原因</th><th>状态</th><th>时间</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentReports as $report): ?>
                                <tr>
                                    <td><a href="<?= e(url('/admin/reports/' . (int) $report['id'])) ?>">#<?= (int) $report['id'] ?></a></td>
                                    <td><?= e($reportTargets[$report['target_type']] ?? $report['target_type']) ?> #<?= (int) $report['target_id'] ?></td>
                                    <td><?= e(report_reason_label($report['reason'])) ?></td>
                                    <td><span class="admin-badge admin-badge--<?= e($report['status']) ?>"><?= e(status_label($report['status'])) ?></span></td>
                                    <td class="muted"><?= e(format_datetime($report['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($memorial['status'] === 'suspended'): ?>
                <div class="admin-panel">
                    <h2>恢复展示</h2>
                    <p class="muted">恢复后纪念馆将重新按原有访问范围展示。请确认暂停原因已经消除。</p>
                    <form method="post" action="<?= e(url('/admin/memorials/' . (int) $memorial['id'] . '/restore')) ?>">
                        <?= csrf_field() ?>
                        <p class="muted" style="font-size:0.84rem;">确认恢复后，公开页面将重新可访问；本次操作会记录到操作日志。</p>
                        <button class="button" type="submit">确认恢复展示</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="admin-panel admin-danger">
                    <h2>暂停展示</h2>
                    <p class="muted">适用于身份存疑、涉及在世人员、侵权投诉或内容违规等情形。暂停后公开页面将不可访问，但不会删除任何数据。</p>
                    <?php if (!empty($errors['reason'])): ?>
                        <div class="form-error" role="alert"><?= e($errors['reason']) ?></div>
                    <?php endif; ?>
                    <form class="form" method="post" action="<?= e(url('/admin/memorials/' . (int) $memorial['id'] . '/suspend')) ?>">
                        <?= csrf_field() ?>
                        <label class="field">
                            <span>暂停理由（5–500 字）</span>
                            <textarea name="reason" maxlength="500" required placeholder="请写明已核实的事实、依据与处理范围。"><?= e($old['reason'] ?? '') ?></textarea>
                            <small class="field__hint">理由会保存在纪念馆状态中，并写入操作日志。</small>
                        </label>
                        <p class="muted" style="font-size:0.84rem;">请确认已完成必要核验。暂停不等同于永久删除，恢复需经过复核。</p>
                        <div>
                            <button class="button button--danger" type="submit">确认暂停纪念馆</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
