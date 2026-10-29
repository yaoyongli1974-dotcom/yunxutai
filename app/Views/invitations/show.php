<article>
    <div class="shell">
        <div class="invite-card">
            <p class="invite-card__type">来自纪念馆的邀请</p>
            <h1><?= e((string) $invitation['full_name']) ?> 的纪念邀请</h1>
            <p class="invite-card__mem">
                馆名：<?= e((string) $invitation['title']) ?><br>
                邀请角色：<span class="chip chip--accent" style="vertical-align:middle;"><?= e(role_label((string) $invitation['role'])) ?></span>
            </p>
            <p class="small muted">
                接受后，你会以「<?= e(role_label((string) $invitation['role'])) ?>」的身份加入这座纪念馆。
                协作者可以先查阅与最后编辑内容，亲友能浏览并祭奠。包含的角色权限由馆主和平台共同管理。
            </p>

            <?php if ($existingRole !== null): ?>
                <div class="banner banner--veil" style="margin:1.2rem 0;">
                    <span class="banner__glyph" aria-hidden="true">已</span>
                    <p>你已经是这座纪念馆的成员（<?= e(role_label($existingRole)) ?>）。这份邀请无需再接受。</p>
                </div>
                <a class="button" href="<?= e(url('/manage/memorials/' . (int) $invitation['memorial_id'])) ?>">进入纪念馆管理</a>
            <?php else: ?>
                <form class="form form--quiet" method="post" action="<?= e(url('/invitations/' . $invitation['token'])) ?>" style="margin-top:1.3rem;">
                    <?= csrf_field() ?>
                    <div class="form__actions">
                        <button class="button" type="submit">接受邀请，加入纪念馆</button>
                        <span class="form__foot">接受后，可在 <a href="<?= e(url('/dashboard')) ?>">我的纪念馆</a> 里找到它。</span>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</article>
