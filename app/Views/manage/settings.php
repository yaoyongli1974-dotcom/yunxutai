<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$visibility = (string) ($old['visibility'] ?? $memorial['visibility']);
$allowSearch = !empty($old['allow_search_index']);
$allowGuestbook = !empty($old['allow_guestbook']);
$moderateMessages = !empty($old['moderate_messages']);
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'settings']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">访问与留言</p>
                <h1>谁可以看见，如何留言</h1>
                <p class="muted" style="margin:0;">默认以隐私为先。公开与搜索收录需要馆主主动选择。</p>
            </div>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <?php $verified = ($memorial['verification_status'] ?? 'none') === 'verified'; ?>
        <?php if (!$verified): ?>
            <div class="notice-card" style="margin-bottom:1rem;">
                <strong>尚未完成身份核验</strong>
                <p>
                    当前纪念馆只能保持私密。请先在
                    <a href="<?= e(url($base . '/verification')) ?>">身份核验</a>
                    提交死亡证明或亲属关系材料；平台审核通过后，才可以改为公开或不公开收录。
                </p>
            </div>
        <?php else: ?>
            <div class="notice-card" style="margin-bottom:1rem;">
                <strong><?= e(verification_label('verified')) ?></strong>
                <p>已通过材料核验，可以选择公开范围。公开后页面会显示核验标记；私密馆不受影响。</p>
            </div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>访问范围</h2>
            <div class="manage-grid">
                <div class="manage-option<?= $visibility === 'public' ? ' manage-option--active' : '' ?>">
                    <strong>公开</strong>
                    可出现在纪念馆列表中，任何访客都能打开。若同时勾选搜索收录，搜索引擎可能建立索引。
                </div>
                <div class="manage-option<?= $visibility === 'unlisted' ? ' manage-option--active' : '' ?>">
                    <strong>不公开收录</strong>
                    不出现在列表，也不会被主动推荐。知道链接的人可以访问，默认不允许搜索收录。
                </div>
                <div class="manage-option<?= $visibility === 'private' ? ' manage-option--active' : '' ?>">
                    <strong>私密</strong>
                    仅馆主、协作者与受邀亲友可见。公开链接对未登录或非成员访客不可用。
                </div>
            </div>

            <form class="form manage-form" method="post" action="<?= e(url($base . '/settings')) ?>">
                <?= csrf_field() ?>

                <label class="field">
                    <span>当前访问范围</span>
                    <select name="visibility">
                        <option value="public"<?= $visibility === 'public' ? ' selected' : '' ?><?= !$verified ? ' disabled' : '' ?>>公开</option>
                        <option value="unlisted"<?= $visibility === 'unlisted' ? ' selected' : '' ?><?= !$verified ? ' disabled' : '' ?>>不公开收录</option>
                        <option value="private"<?= $visibility === 'private' ? ' selected' : '' ?>>私密</option>
                    </select>
                    <?php if (!empty($errors['visibility'])): ?><small class="field__error"><?= e($errors['visibility']) ?></small><?php endif; ?>
                </label>

                <label class="checkbox">
                    <input type="checkbox" name="allow_search_index" value="1"<?= $allowSearch ? ' checked' : '' ?>>
                    <span>
                        允许搜索引擎收录（仅“公开”范围下有效）<br>
                        <span class="muted">关闭时，页面会输出 noindex。修改为非公开范围后，本项会自动关闭。</span>
                    </span>
                </label>

                <hr style="border:0;border-top:1px solid var(--line);margin:0.4rem 0;">

                <h2 style="font-size:1.05rem;margin:0.2rem 0 0;">留言与祭奠</h2>
                <label class="checkbox">
                    <input type="checkbox" name="allow_guestbook" value="1"<?= $allowGuestbook ? ' checked' : '' ?>>
                    <span>
                        开放留言板<br>
                        <span class="muted">关闭后，访客仍可查看已有留言，但不能提交新留言。</span>
                    </span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="moderate_messages" value="1"<?= $moderateMessages ? ' checked' : '' ?>>
                    <span>
                        留言先审后发<br>
                        <span class="muted">开启时，新留言进入待审核状态，由馆主或协作者在“内容审核”中决定是否展示；关闭后立即展示。</span>
                    </span>
                </label>

                <div class="manage-actions-row">
                    <button class="button" type="submit">保存设置</button>
                    <a class="text-link" href="<?= e(url($base)) ?>">返回概况</a>
                </div>
            </form>
        </section>

        <section class="manage-section">
            <h2>关于隐私的说明</h2>
            <p class="muted" style="margin:0;">
                纪念馆不设排行榜、积分或虚拟香火消费。祭奠与留言只作为纪念记录保存。
                如果纪念馆信息涉及仍在世人员、隐私或权益争议，任何访客都可以通过举报入口提交说明，平台会核查处理。
            </p>
        </section>
    </div>
</div>
