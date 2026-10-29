<?php
$statusOptions = ['' => '全部状态', 'new' => '新申请', 'contacted' => '已联系', 'closed' => '已关闭'];
$serviceTypes = [
    'photo_restore' => '老照片修复',
    'memorial_video' => '纪念视频制作',
    'life_archive' => '生平文章整理',
    'family_archive' => '家族数字档案',
    'institution' => '机构合作',
    'other' => '其他',
];
$oldId = (int) ($old['id'] ?? 0);
?>
<section class="section">
    <div class="shell admin-shell">
        <?php App\Core\View::partial('admin/_nav', ['active' => 'services']); ?>
        <div class="admin-main">
            <div class="admin-page-head">
                <div>
                    <p class="eyebrow">服务进度</p>
                    <h1>服务申请</h1>
                    <p class="muted">跟进老照片修复、纪念视频与档案整理等服务的联系进度。</p>
                </div>
            </div>

            <div class="admin-note">
                服务申请只记录联系与交付进度，不涉及虚拟祭品或线上支付。对外报价应以可交付内容为准，并明确服务期限与数据处置方式。
            </div>

            <?php foreach ($errors as $message): ?>
                <div class="form-error" role="alert" style="margin-bottom:0.8rem;"><?= e($message) ?></div>
            <?php endforeach; ?>

            <form class="admin-filters" method="get" action="<?= e(url('/admin/services')) ?>">
                <label class="field">
                    <span>状态</span>
                    <select name="status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button button--small" type="submit">筛选</button>
                <a class="button button--small button--ghost" href="<?= e(url('/admin/services')) ?>">重置</a>
            </form>

            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>申请人</th>
                        <th>服务类型</th>
                        <th>留言</th>
                        <th>关联纪念馆</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th>处理</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests === []): ?>
                        <tr><td colspan="7" class="muted">没有符合条件的服务申请。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $service): ?>
                        <tr>
                            <td>
                                <strong><?= e($service['name']) ?></strong>
                                <p class="muted"><?= e($service['contact']) ?></p>
                            </td>
                            <td><?= e($serviceTypes[$service['service_type']] ?? $service['service_type']) ?></td>
                            <td>
                                <?php if (!empty($service['message'])): ?>
                                    <?= nl2br(e(excerpt_text($service['message'], 220))) ?>
                                <?php else: ?>
                                    <span class="muted">未填写</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($service['memorial_id'])): ?>
                                    <a href="<?= e(url('/admin/memorials/' . (int) $service['memorial_id'])) ?>">纪念馆 #<?= (int) $service['memorial_id'] ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="admin-badge admin-badge--<?= e($service['status']) ?>"><?= e(status_label($service['status'])) ?></span></td>
                            <td class="muted">
                                <?= e(format_datetime($service['created_at'])) ?>
                                <?php if (!empty($service['handled_at'])): ?>
                                    <p>处理：<?= e(format_datetime($service['handled_at'])) ?></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-inline-form" method="post" action="<?= e(url('/admin/services/' . (int) $service['id'])) ?>">
                                    <?= csrf_field() ?>
                                    <label class="field">
                                        <span>状态</span>
                                        <select name="status" required>
                                            <?php foreach (['new' => '新申请', 'contacted' => '已联系', 'closed' => '已关闭'] as $value => $label): ?>
                                                <option value="<?= e($value) ?>"<?= ($oldId === (int) $service['id'] ? ($old['status'] ?? $service['status']) : $service['status']) === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="button button--small" type="submit">更新状态</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php App\Core\View::partial('admin/_pagination', [
                'paginator' => $paginator,
                'path' => '/admin/services',
                'query' => $query,
            ]); ?>
        </div>
    </div>
</section>
