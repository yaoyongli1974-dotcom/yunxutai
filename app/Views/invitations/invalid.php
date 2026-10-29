<article>
    <div class="shell">
        <div class="invite-card">
            <p class="invite-card__type">邀请链接</p>
            <h1 style="font-size:1.3rem;">这份邀请已失效</h1>
            <p class="small muted" style="margin-bottom:1rem;">
                可能是因为：邀请已被接受、已被馆主撤回，或已过有效期（邀请一般 7 天内有效）。
                请联系纪念馆的家人重新发一份，链接只指向他们愿意邀请的人。
            </p>
            <?php if (auth_user() === null): ?>
                <p class="small muted" style="margin-bottom:1.2rem;">接受邀请需要先登录。</p>
                <a class="button button--small" href="<?= e(url('/login')) ?>">登录</a>
                <a class="button button--small button--ghost" href="<?= e(url('/register')) ?>">创建账号</a>
            <?php else: ?>
                <a class="button button--small button--ghost" href="<?= e(url('/dashboard')) ?>">回到我的纪念馆</a>
            <?php endif; ?>
        </div>
    </div>
</article>
