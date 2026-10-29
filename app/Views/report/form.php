<article>
    <div class="shell">
        <div class="page-head">
            <p class="eyebrow">投诉与举报</p>
            <h1>你想告诉我们什么</h1>
            <p class="page-head__meta">提交后进入平台后台处理，不公开你的身份；如需回执，请留下联系方式。</p>
        </div>
    </div>

    <div class="section--tight" style="padding-bottom:3.5rem;">
        <div class="shell report-shell">
            <div>
                <div class="report-target">
                    <p class="report-target__label"><?= e($target['label']) ?></p>
                    <?php if ((string) $target['snippet'] !== ''): ?>
                        <p class="report-target__snippet"><?= e($target['snippet']) ?></p>
                    <?php endif; ?>
                    <p class="report-target__snippet">所属纪念馆：<a href="<?= e(url('/m/' . $target['memorial']['slug'])) ?>"><?= e($target['memorial']['title']) ?></a></p>
                </div>

                <?php if (!empty($errors['form'])): ?>
                    <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                        <p><?= e($errors['form']) ?></p>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= e(url('/report')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="target_type" value="<?= e($target['type']) ?>">
                    <input type="hidden" name="target_id" value="<?= (int) $target['id'] ?>">

                    <fieldset class="field" style="gap:0.4rem;">
                        <legend>举报类型</legend>
                        <p class="muted small" style="margin:0 0 0.4rem;">“被祭奠者仍在世”和“纪念馆信息不实”属于紧急事项，平台会优先核查并视情况先行暂停展示。</p>
                        <div class="radio-list">
                            <label><input type="radio" name="reason" value="fake_person" <?= ($old['reason'] ?? '') === 'fake_person' ? 'checked' : '' ?> required> 纪念馆信息不实（名字、生平与事实不符）</label>
                            <label><input type="radio" name="reason" value="living_person" <?= ($old['reason'] ?? '') === 'living_person' ? 'checked' : '' ?>> 被祭奠者仍在世</label>
                            <label><input type="radio" name="reason" value="privacy" <?= ($old['reason'] ?? '') === 'privacy' ? 'checked' : '' ?>> 侵犯隐私或肖像权</label>
                            <label><input type="radio" name="reason" value="insult" <?= ($old['reason'] ?? '') === 'insult' ? 'checked' : '' ?>> 侮辱、诽谤内容</label>
                            <label><input type="radio" name="reason" value="illegal" <?= ($old['reason'] ?? '') === 'illegal' ? 'checked' : '' ?>> 违法违规内容</label>
                            <label><input type="radio" name="reason" value="copyright" <?= ($old['reason'] ?? '') === 'copyright' ? 'checked' : '' ?>> 版权问题</label>
                            <label><input type="radio" name="reason" value="other" <?= ($old['reason'] ?? '') === 'other' ? 'checked' : '' ?>> 其他</label>
                        </div>
                        <?php if (!empty($errors['reason'])): ?><small class="field__error"><?= e($errors['reason']) ?></small><?php endif; ?>
                    </fieldset>

                    <label class="field">
                        <span>具体情况（10–2000 字）</span>
                        <textarea name="detail" rows="6" minlength="10" maxlength="2000" required placeholder="写清你知道的事实：与被举报对象的关系、有哪些材料可以佐证、希望平台如何处理。"><?= e($old['detail'] ?? '') ?></textarea>
                        <?php if (!empty($errors['detail'])): ?><small class="field__error"><?= e($errors['detail']) ?></small><?php endif; ?>
                    </label>

                    <label class="field">
                        <span>你的称呼</span>
                        <input type="text" name="reporter_name" value="<?= e($old['reporter_name'] ?? (auth_user()['display_name'] ?? '')) ?>" maxlength="80" required>
                        <?php if (!empty($errors['reporter_name'])): ?><small class="field__error"><?= e($errors['reporter_name']) ?></small><?php endif; ?>
                    </label>

                    <label class="field">
                        <span>联系方式（选填，仅用于反馈处理结果）</span>
                        <input type="text" name="contact" value="<?= e($old['contact'] ?? (auth_user()['email'] ?? '')) ?>" maxlength="190">
                        <?php if (!empty($errors['contact'])): ?><small class="field__error"><?= e($errors['contact']) ?></small><?php endif; ?>
                    </label>

                    <div class="captcha-row">
                        <span class="captcha-q" aria-hidden="true">验证：<?= (int) ($captcha['a'] ?? 0) ?> + <?= (int) ($captcha['b'] ?? 0) ?></span>
                        <label class="field">
                            <span class="visually-hidden">验证码</span>
                            <input type="text" name="captcha" inputmode="numeric" autocomplete="off" required placeholder="填写两数之和">
                            <?php if (!empty($errors['captcha'])): ?><small class="field__error"><?= e($errors['captcha']) ?></small><?php endif; ?>
                        </label>
                    </div>

                    <div class="form__actions">
                        <button class="button" type="submit">提交举报</button>
                        <a class="button button--ghost" href="<?= e(url('/about')) ?>#reporting">先看流程说明</a>
                    </div>
                </form>
            </div>

            <aside class="report-aside">
                <h3>提交之后会发生什么</h3>
                <ol>
                    <li>举报进入平台后台，先做<b>形式核查</b>（是否可核实、是否重复提交）；</li>
                    <li>必要时先<b>暂停相关内容或纪念馆展示</b>，再向双方了解情况；</li>
                    <li>处理结果（已处理 / 已驳回 / 处理中）留存于后台，留下了联系方式的会以邮件告知；</li>
                    <li>涉及明显违反法律的，会移交相关平台主管部门或配合有关部门处理。</li>
                </ol>
                <h3>紧急情况</h3>
                <p>人身安全或正在发生的违法行为，请先报警（110），并可以同时向我们提交举报作为佐证记录。</p>
                <h3>恶意举报</h3>
                <p>伪造材料或反复骚扰性提交将导致账号受限。请实事求是，我们也会实事求是。</p>
            </aside>
        </div>
    </div>
</article>
