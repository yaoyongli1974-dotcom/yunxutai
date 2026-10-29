<?php
$reportTargets = ['memorial' => '纪念馆', 'message' => '留言', 'tribute' => '祭奠记录', 'media' => '影像', 'article' => '文章'];
$serviceTypes = [
    'photo_restore' => '老照片修复',
    'memorial_video' => '纪念视频',
    'life_archive' => '生平整理',
    'family_archive' => '家族档案',
    'institution' => '机构合作',
    'other' => '其他',
];
$dataTypes = ['export' => '数据导出', 'delete' => '数据删除', 'revoke' => '撤回授权'];
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'dashboard']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">今日待办</p>
                    <h1>运营概览</h1>
                    <p class="muted">审核内容、处理举报与申领，并跟进用户的服务与数据请求。</p>
                </div>
            </div>

            <div class="admin-stats">
                <div class="admin-stat"><strong><?= (int) $summary['users'] ?></strong><span>普通用户</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['memorials_total'] ?></strong><span>纪念馆总数</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['memorials_active'] ?></strong><span>展示中</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['memorials_draft'] ?></strong><span>草稿</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['memorials_suspended'] ?></strong><span>已暂停</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['pending_messages'] ?></strong><span>待审留言</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['pending_articles'] ?></strong><span>待审文章</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['pending_verifications'] ?></strong><span>待核验身份</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['open_reports'] ?></strong><span>待处理举报</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['open_claims'] ?></strong><span>待处理申领</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['new_services'] ?></strong><span>新服务申请</span></div>
                <div class="admin-stat"><strong><?= (int) $summary['open_data_requests'] ?></strong><span>待处理数据申请</span></div>
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <div>
                            <h2>待核验身份</h2>
                            <p class="muted">公开纪念馆前必须人工核对死亡证明或亲属关系材料。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/verifications')) ?>">进入核验</a>
                    </div>
                    <?php if ($pendingVerifications === []): ?>
                        <p class="admin-empty">暂无待核验申请。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>纪念馆</th><th>提交人</th><th>材料</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingVerifications as $verification): ?>
                                    <tr>
                                        <td><a href="<?= e(url('/admin/verifications/' . (int) $verification['id'])) ?>"><?= e($verification['deceased_name']) ?></a></td>
                                        <td><?= e($verification['submitter_name']) ?></td>
                                        <td class="muted"><?= e(verification_method_label((string) $verification['method'])) ?></td>
                                        <td class="muted"><?= e(format_datetime((string) $verification['created_at'])) ?></td>
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
                            <h2>待审留言</h2>
                            <p class="muted">按提交时间从早到晚，优先处理较早内容。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/moderation')) ?>">进入审核</a>
                    </div>
                    <?php if ($pendingMessages === []): ?>
                        <p class="admin-empty">暂无待审留言。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>留言</th><th>纪念馆</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingMessages as $message): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($message['author_name']) ?></strong>
                                            <p class="muted"><?= e(excerpt_text($message['content'], 56)) ?></p>
                                        </td>
                                        <td><a href="<?= e(url('/admin/memorials/' . (int) $message['memorial_id'])) ?>"><?= e($message['full_name']) ?></a></td>
                                        <td class="muted"><?= e(format_datetime($message['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <div>
                            <h2>待审文章</h2>
                            <p class="muted">通过后文章将公开展示；驳回时建议写明原因。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/moderation')) ?>">进入审核</a>
                    </div>
                    <?php if ($pendingArticles === []): ?>
                        <p class="admin-empty">暂无待审文章。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>文章</th><th>纪念馆</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($pendingArticles as $article): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($article['title']) ?></strong>
                                            <p class="muted"><?= e($article['author_name'] ?: '未署名') ?></p>
                                        </td>
                                        <td><a href="<?= e(url('/admin/memorials/' . (int) $article['memorial_id'])) ?>"><?= e($article['full_name']) ?></a></td>
                                        <td class="muted"><?= e(format_datetime($article['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <div>
                            <h2>待处理举报</h2>
                            <p class="muted">处理前请核对目标内容与举报材料。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/reports')) ?>">全部举报</a>
                    </div>
                    <?php if ($openReports === []): ?>
                        <p class="admin-empty">暂无待处理举报。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>目标</th><th>原因</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($openReports as $report): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= e(url('/admin/reports/' . (int) $report['id'])) ?>">#<?= (int) $report['id'] ?> <?= e($reportTargets[$report['target_type']] ?? $report['target_type']) ?></a>
                                            <?php if (!empty($report['memorial_name'])): ?>
                                                <p class="muted"><?= e($report['memorial_name']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(report_reason_label($report['reason'])) ?></td>
                                        <td class="muted"><?= e(format_datetime($report['created_at'])) ?></td>
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
                            <h2>待处理申领</h2>
                            <p class="muted">需核验身份与授权；通过后仅授予协作者权限。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/claims')) ?>">全部申领</a>
                    </div>
                    <?php if ($openClaims === []): ?>
                        <p class="admin-empty">暂无待处理申领。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>申请人</th><th>纪念馆</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($openClaims as $claim): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($claim['display_name']) ?></strong>
                                            <p class="muted"><?= e($claim['relationship']) ?></p>
                                        </td>
                                        <td><a href="<?= e(url('/admin/claims')) ?>"><?= e($claim['memorial_name']) ?></a></td>
                                        <td class="muted"><?= e(format_datetime($claim['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-split">
                <div class="admin-panel">
                    <div class="admin-panel__head">
                        <div>
                            <h2>新服务申请</h2>
                            <p class="muted">仅登记联系进度，不涉及线上支付。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/services')) ?>">全部申请</a>
                    </div>
                    <?php if ($newServices === []): ?>
                        <p class="admin-empty">暂无新服务申请。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>申请人</th><th>服务</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($newServices as $service): ?>
                                    <tr>
                                        <td><?= e($service['name']) ?></td>
                                        <td><?= e($serviceTypes[$service['service_type']] ?? $service['service_type']) ?></td>
                                        <td class="muted"><?= e(format_datetime($service['created_at'])) ?></td>
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
                            <h2>数据申请</h2>
                            <p class="muted">需核验身份与授权后离线处理，不在此页删除数据。</p>
                        </div>
                        <a class="text-link" href="<?= e(url('/admin/data-requests')) ?>">全部申请</a>
                    </div>
                    <?php if ($openDataRequests === []): ?>
                        <p class="admin-empty">暂无待处理数据申请。</p>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>用户</th><th>类型</th><th>时间</th></tr></thead>
                                <tbody>
                                <?php foreach ($openDataRequests as $dataRequest): ?>
                                    <tr>
                                        <td><?= e($dataRequest['display_name']) ?></td>
                                        <td><?= e($dataTypes[$dataRequest['type']] ?? $dataRequest['type']) ?></td>
                                        <td class="muted"><?= e(format_datetime($dataRequest['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
