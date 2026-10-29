<article>
    <div class="shell">
        <header class="dash-head">
            <div>
                <p class="eyebrow">账号</p>
                <h1>账号设置</h1>
                <p class="dash-head__greet">当前邮箱：<?= e((string) $user['email']) ?>（登录邮箱不可修改，如确实需要请联系平台）</p>
            </div>
        </header>

        <?php $currentTab = in_array($tab ?? 'profile', ['profile', 'security', 'privacy', '2fa'], true) ? $tab : 'profile'; ?>
        <nav class="tabs" aria-label="设置分区">
            <a href="<?= e(url('/account?tab=profile')) ?>"<?= $currentTab === 'profile' ? ' aria-current="true"' : '' ?>>基本资料</a>
            <a href="<?= e(url('/account?tab=security')) ?>"<?= $currentTab === 'security' ? ' aria-current="true"' : '' ?>>登录安全</a>
            <a href="<?= e(url('/account?tab=2fa')) ?>"<?= $currentTab === '2fa' ? ' aria-current="true"' : '' ?>>二次验证</a>
            <a href="<?= e(url('/account?tab=privacy')) ?>"<?= $currentTab === 'privacy' ? ' aria-current="true"' : '' ?>>数据与隐私</a>
        </nav>

        <div class="section--tight" style="padding-bottom:3.5rem;">
            <?php if ($currentTab === 'profile'): ?>
                <?php /* ---------- 基本资料 ---------- */ ?>
                <section style="max-width:32rem;">
                    <h2 style="font-size:1.2rem;">基本资料</h2>
                    <p class="small muted">这里的称呼会显示在你留下的祭奠、留言与申请旁。</p>

                    <?php if (!empty($errors['form'])): ?>
                        <div class="form-error" role="alert" style="margin-bottom:1rem;"><p><?= e($errors['form']) ?></p></div>
                    <?php endif; ?>

                    <form class="form" method="post" action="<?= e(url('/account/profile')) ?>">
                        <?= csrf_field() ?>
                        <label class="field">
                            <span>称呼</span>
                            <input type="text" name="display_name" value="<?= e($old['display_name'] ?? (string) $user['display_name']) ?>" maxlength="40" required>
                            <?php if (!empty($errors['display_name'])): ?><small class="field__error"><?= e($errors['display_name']) ?></small><?php endif; ?>
                        </label>
                        <label class="field">
                            <span>手机号（选填，便于家人紧急联系你）</span>
                            <input type="tel" name="phone" value="<?= e($old['phone'] ?? (string) ($user['phone'] ?? '')) ?>" maxlength="32" placeholder="如 13800001234">
                            <small class="field__hint">不会展示给其他用户，仅用于平台与你联系。</small>
                            <?php if (!empty($errors['phone'])): ?><small class="field__error"><?= e($errors['phone']) ?></small><?php endif; ?>
                        </label>
                        <div class="form__actions">
                            <button class="button" type="submit">保存资料</button>
                        </div>
                    </form>
                </section>

            <?php elseif ($currentTab === 'security'): ?>
                <?php /* ---------- 登录安全 ---------- */ ?>
                <section style="max-width:32rem;">
                    <h2 style="font-size:1.2rem;">修改密码</h2>
                    <p class="small muted">建议使用与其它网站不同的密码，长度 10 位以上，包含字母与数字。</p>

                    <?php if (!empty($errors['form'])): ?>
                        <div class="form-error" role="alert" style="margin-bottom:1rem;"><p><?= e($errors['form']) ?></p></div>
                    <?php endif; ?>

                    <form class="form" method="post" action="<?= e(url('/account/password')) ?>">
                        <?= csrf_field() ?>
                        <label class="field">
                            <span>当前密码</span>
                            <input type="password" name="current_password" autocomplete="current-password" required>
                            <?php if (!empty($errors['current_password'])): ?><small class="field__error"><?= e($errors['current_password']) ?></small><?php endif; ?>
                        </label>
                        <label class="field">
                            <span>新密码</span>
                            <input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required>
                            <?php if (!empty($errors['password'])): ?><small class="field__error"><?= e($errors['password']) ?></small><?php endif; ?>
                        </label>
                        <label class="field">
                            <span>确认新密码</span>
                            <input type="password" name="password_confirmation" minlength="10" maxlength="72" autocomplete="new-password" required>
                            <?php if (!empty($errors['password_confirmation'])): ?><small class="field__error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>
                        </label>
                        <div class="form__actions">
                            <button class="button" type="submit">更新密码</button>
                            <span class="form__foot">修改后需要重新登录其它设备。</span>
                        </div>
                    </form>
                </section>

            <?php elseif ($currentTab === '2fa'): ?>
                <?php /* ---------- 二次验证 ---------- */ ?>
                <?php App\Core\View::partial('account/security', [
                    'user' => $user,
                    'errors' => $errors,
                    'totpEnabled' => $totpEnabled ?? false,
                    'pendingSecret' => $pendingSecret ?? null,
                    'otpauthUri' => $otpauthUri ?? null,
                ]); ?>

            <?php else: ?>
                <?php /* ---------- 数据与隐私 ---------- */ ?>
                <section class="report-shell" style="padding:0;">
                    <div>
                        <h2 style="font-size:1.2rem;">数据与隐私申请</h2>
                        <p class="small muted" style="margin-bottom:1.2rem;">导出账号数据、删除纪念馆相关授权、撤回照片使用许可等，均从下面提交。为防止冒名操作，平台会先核验身份再处理，通常在 5 个工作日内通过你已绑定的邮箱回复进展。</p>

                        <?php if (!empty($errors['form'])): ?>
                            <div class="form-error" role="alert" style="margin-bottom:1rem;"><p><?= e($errors['form']) ?></p></div>
                        <?php endif; ?>

                        <form class="form" method="post" action="<?= e(url('/account/data-request')) ?>">
                            <?= csrf_field() ?>
                            <fieldset class="field" style="gap:0.4rem;">
                                <legend>申请类型</legend>
                                <div class="radio-list">
                                    <label><input type="radio" name="type" value="export" <?= ($old['type'] ?? '') === 'export' ? 'checked' : '' ?> required> 导出我的数据（留言、祭奠与账号资料）</label>
                                    <label><input type="radio" name="type" value="delete" <?= ($old['type'] ?? '') === 'delete' ? 'checked' : '' ?>> 删除账号或部分内容</label>
                                    <label><input type="radio" name="type" value="revoke" <?= ($old['type'] ?? '') === 'revoke' ? 'checked' : '' ?>> 撤回某次授权（如撤回照片在公开馆的展示）</label>
                                </div>
                                <?php if (!empty($errors['type'])): ?><small class="field__error"><?= e($errors['type']) ?></small><?php endif; ?>
                            </fieldset>
                            <label class="field">
                                <span>补充说明（选填，1000 字以内）</span>
                                <textarea name="note" rows="4" maxlength="1000" placeholder="涉及具体纪念馆或照片时，请写明馆名或标题，便于平台处理。"><?= e($old['note'] ?? '') ?></textarea>
                                <?php if (!empty($errors['note'])): ?><small class="field__error"><?= e($errors['note']) ?></small><?php endif; ?>
                            </label>
                            <div class="form__actions">
                                <button class="button" type="submit">提交申请</button>
                                <span class="form__foot">同一类申请每天最多提交 3 次。</span>
                            </div>
                        </form>
                    </div>

                    <aside class="report-aside">
                        <h3>我的申请记录</h3>
                        <?php if ($requests !== []): ?>
                            <div class="table-wrap" style="border:1px solid var(--line);border-radius:var(--radius);">
                                <table class="table">
                                    <thead>
                                        <tr><th>类型</th><th>状态</th><th>提交时间</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requests as $request): ?>
                                            <tr>
                                                <td><?= e(match ((string) $request['type']) {
                                                    'export' => '导出数据',
                                                    'delete' => '删除申请',
                                                    'revoke' => '撤回授权',
                                                    default => (string) $request['type'],
                                                }) ?></td>
                                                <td><?= e(status_label((string) $request['status'])) ?></td>
                                                <td><?= e(format_datetime((string) $request['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="muted small">还没有记录。提交后，这里会显示每次申请的处理状态。</p>
                        <?php endif; ?>
                        <h3 style="margin-top:1.4rem;">为什么需要人工核验</h3>
                        <p class="muted small">账号涉及逝者资料与家人共同维护的内容，误删不可轻易恢复。核对身份后再执行，是为了保护你也保护馆里的其他成员。</p>
                    </aside>
                </section>
            <?php endif; ?>
        </div>
    </div>
</article>
