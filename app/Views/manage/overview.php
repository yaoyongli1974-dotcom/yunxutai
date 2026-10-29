<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$publicUrl = '/m/' . $memorial['slug'];
$status = (string) $memorial['status'];
$isPlatformSuspended = !empty($memorial['suspended_by']);
$canResume = $canManage && (!$isPlatformSuspended || $isAdmin);
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'overview']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">纪念馆概况</p>
                <h1><?= e($memorial['title']) ?></h1>
                <p class="muted" style="margin:0;">
                    <?= e($role !== null ? role_label((string) $role) : '访客') ?> ·
                    <span class="manage-badge manage-badge--<?= e($status) ?>"><?= e(status_label($status)) ?></span>
                    <span class="manage-badge"><?= e(visibility_label((string) $memorial['visibility'])) ?></span>
                </p>
            </div>
            <div class="manage-actions">
                <?php if ($status === 'active' && $canManage): ?>
                    <form method="post" action="<?= e(url($base . '/pause')) ?>" data-confirm="暂停后访客将无法访问该纪念馆。确认暂停？">
                        <?= csrf_field() ?>
                        <button class="button button--small button--ghost" type="submit">暂停展示</button>
                    </form>
                <?php elseif ($status === 'draft' && $canManage): ?>
                    <form method="post" action="<?= e(url($base . '/publish')) ?>">
                        <?= csrf_field() ?>
                        <button class="button button--small" type="submit">发布纪念馆</button>
                    </form>
                <?php elseif ($status === 'suspended' && $canResume): ?>
                    <form method="post" action="<?= e(url($base . '/resume')) ?>">
                        <?= csrf_field() ?>
                        <button class="button button--small" type="submit">恢复展示</button>
                    </form>
                <?php endif; ?>
                <a class="button button--small button--ghost" href="<?= e(url($publicUrl)) ?>">查看公开页面</a>
            </div>
        </header>

        <?php $verificationStatus = (string) ($memorial['verification_status'] ?? 'none'); ?>
        <?php if ($canManage && $verificationStatus !== 'verified'): ?>
            <div class="notice-card" style="margin-bottom:1rem;">
                <strong><?= e(verification_label($verificationStatus)) ?></strong>
                <p>
                    纪念馆目前只能以私密方式展示。公开或不公开收录前，请提交死亡证明或亲属关系材料。
                    <a href="<?= e(url($base . '/verification')) ?>">前往身份核验</a>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($status === 'draft'): ?>
            <div class="manage-section manage-section--state manage-section--draft">
                <span class="manage-section--state__glyph" aria-hidden="true">稿</span>
                <div>
                    <h2 style="border-bottom:0;padding-bottom:0;margin-bottom:0.5rem;">纪念馆尚未发布</h2>
                    <p class="muted" style="margin-bottom:0.8rem;">
                        当前只有馆主与协作者可以看到这座纪念馆。补充生平、照片和文章，确认信息无误后即可发布。
                        发布后，访问范围仍由“访问与留言”设置决定。
                    </p>
                    <div class="manage-actions-row">
                        <a class="button button--small" href="<?= e(url($base . '/profile')) ?>">补充纪念馆资料</a>
                        <a class="button button--small button--ghost" href="<?= e(url($base . '/life')) ?>">添加生平</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status === 'suspended'): ?>
            <div class="manage-section manage-section--state manage-section--suspended">
                <span class="manage-section--state__glyph" aria-hidden="true">停</span>
                <div>
                    <h2 style="border-bottom:0;padding-bottom:0;margin-bottom:0.5rem;">纪念馆当前已暂停</h2>
                    <p class="muted">
                        <?php if ($isPlatformSuspended): ?>
                            暂停原因：<?= e($memorial['suspended_reason'] ?: '由平台暂停展示') ?>。
                            如有疑问，请通过页脚的联系渠道与平台沟通。
                        <?php else: ?>
                            <?= e($memorial['suspended_reason'] ?: '馆主暂停展示') ?>。暂停期间访客无法访问，恢复后可继续。
                        <?php endif; ?>
                    </p>
                    <?php if ($isPlatformSuspended && !$isAdmin): ?>
                        <p class="manage-note manage-note--warn">平台暂停期间，馆主不能自行恢复展示。</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>访问地址</h2>
            <p class="muted" style="margin-bottom:0.5rem;">公开页面地址：</p>
            <p><a href="<?= e(url($publicUrl)) ?>"><?= e(url($publicUrl)) ?></a></p>
            <?php if ((int) $memorial['allow_search_index'] === 1 && $memorial['visibility'] === 'public' && $status === 'active'): ?>
                <p class="manage-note">已允许搜索引擎收录。</p>
            <?php else: ?>
                <p class="manage-note">当前不会被搜索引擎收录。可在“访问与留言”中调整，公开范围下才可开启收录。</p>
            <?php endif; ?>
            <?php if (!empty($memorial['published_at'])): ?>
                <p class="manage-note">首次发布于 <?= e(format_datetime($memorial['published_at'])) ?></p>
            <?php endif; ?>
        </section>

        <section class="manage-section">
            <h2>内容概览</h2>
            <div class="manage-grid">
                <div class="manage-stat">
                    <div class="manage-stat__number"><?= (int) $counts['tributes'] ?></div>
                    <div class="manage-stat__label">公开祭奠记录</div>
                    <a class="text-link" href="<?= e(url($publicUrl . '/tributes')) ?>">查看</a>
                </div>
                <div class="manage-stat">
                    <div class="manage-stat__number"><?= (int) $counts['messages'] ?></div>
                    <div class="manage-stat__label">公开留言</div>
                    <a class="text-link" href="<?= e(url($publicUrl . '/messages')) ?>">查看</a>
                </div>
                <div class="manage-stat">
                    <div class="manage-stat__number"><?= (int) $counts['photos'] ?></div>
                    <div class="manage-stat__label">展示中的照片</div>
                    <?php if (App\Core\Policy::canEdit($memorial, auth_user())): ?>
                        <a class="text-link" href="<?= e(url($base . '/media')) ?>">管理</a>
                    <?php endif; ?>
                </div>
                <div class="manage-stat">
                    <div class="manage-stat__number"><?= (int) $counts['articles'] ?></div>
                    <div class="manage-stat__label">已发布文章</div>
                    <?php if (App\Core\Policy::canEdit($memorial, auth_user())): ?>
                        <a class="text-link" href="<?= e(url($base . '/articles')) ?>">管理</a>
                    <?php endif; ?>
                </div>
                <div class="manage-stat">
                    <div class="manage-stat__number"><?= (int) $counts['members'] ?></div>
                    <div class="manage-stat__label">纪念馆成员</div>
                    <?php if ($canManage): ?>
                        <a class="text-link" href="<?= e(url($base . '/members')) ?>">管理</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="manage-section">
            <h2>接下来可以做的事</h2>
            <div class="manage-actions-row">
                <?php if (App\Core\Policy::canEdit($memorial, auth_user())): ?>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/profile')) ?>">完善纪念馆资料</a>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/life')) ?>">整理生平</a>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/articles')) ?>">撰写纪念文章</a>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/media')) ?>">上传照片与文件</a>
                <?php endif; ?>
                <?php if (App\Core\Policy::canModerate($memorial, auth_user())): ?>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/moderation')) ?>">审核留言与祭奠</a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <a class="button button--small button--ghost" href="<?= e(url($base . '/settings')) ?>">访问与留言设置</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
