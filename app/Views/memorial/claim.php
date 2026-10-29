<article>
    <div class="shell">
        <div class="page-head">
            <nav class="crumbs" aria-label="位置">
                <a href="<?= e(url('/')) ?>">首页</a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <a href="<?= e(url('/m/' . $memorial['slug'])) ?>"><?= e($memorial['title']) ?></a>
                <span class="crumbs__sep" aria-hidden="true">·</span>
                <span>申请管理</span>
            </nav>
            <p class="eyebrow">家属认领</p>
            <h1>申请管理“<?= e($memorial['title']) ?>”</h1>
            <p class="page-head__meta">逝者：<?= e((string) $memorial['full_name']) ?><?= life_dates($memorial) !== '' ? ' · ' . e(life_dates($memorial)) : '' ?></p>
        </div>
    </div>

    <div class="section--tight" style="padding-bottom:3.5rem;">
        <div class="shell report-shell">
            <div>
                <?php if (!empty($errors['form'])): ?>
                    <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                        <p><?= e($errors['form']) ?></p>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= e(url('/m/' . $memorial['slug'] . '/claim')) ?>">
                    <?= csrf_field() ?>

                    <label class="field">
                        <span>你与<?= e((string) $memorial['full_name']) ?>的关系</span>
                        <input type="text" name="relationship" value="<?= e($old['relationship'] ?? '') ?>" maxlength="120" required placeholder="例如：长子 / 外孙女 / 学生">
                        <?php if (!empty($errors['relationship'])): ?><small class="field__error"><?= e($errors['relationship']) ?></small><?php endif; ?>
                    </label>

                    <label class="field">
                        <span>申请说明（20–2000 字）</span>
                        <textarea name="statement" rows="7" minlength="20" maxlength="2000" required placeholder="请说明：这个馆的实际情况应当如何维护、你知道哪些只有家人才了解的史料、你希望承担的整理工作。详细的陈述会帮助你更快通过核验。"><?= e($old['statement'] ?? '') ?></textarea>
                        <small class="field__hint">为帮助核验，请把口述内容与已知日期写具体。</small>
                        <?php if (!empty($errors['statement'])): ?><small class="field__error"><?= e($errors['statement']) ?></small><?php endif; ?>
                    </label>

                    <label class="field">
                        <span>可以提供的证明材料（选填，1000 字以内）</span>
                        <textarea name="evidence_note" rows="4" maxlength="1000" placeholder="例如：手写的记账本、医院档案编号、家庭合照的拍摄时间、长辈生前的书信。"></textarea>
                        <?php if (!empty($errors['evidence_note'])): ?><small class="field__error"><?= e($errors['evidence_note']) ?></small><?php endif; ?>
                    </label>

                    <div class="form__actions">
                        <button class="button" type="submit">提交申请</button>
                        <a class="button button--ghost" href="<?= e(url('/m/' . $memorial['slug'])) ?>">先不提交</a>
                    </div>
                </form>
            </div>

            <aside class="report-aside">
                <h3>核验是怎么进行的</h3>
                <ol>
                    <li>提交后，申请会进入平台的<b>管理后台</b>，由管理员人工核验，通常在 3 个工作日内与你留下的联系方式回复。</li>
                    <li>核验通过且现有馆主确认后，你会获得对应角色；双方存在争议时，平台会先与现馆主沟通。</li>
                    <li>对故意冒充亲属伪造申请的账号，平台会<b>永久停用</b>并保留追究权利。</li>
                </ol>
                <h3>请注意</h3>
                <ul>
                    <li>同一座纪念馆只允许保留一条待处理申请。</li>
                    <li>请勿提交他人的身份材料。</li>
                    <li>如果你已是该馆成员，无需申请：<a href="<?= e(url('/dashboard')) ?>">我的纪念馆</a>中可以直接进入管理。</li>
                </ul>
            </aside>
        </div>
    </div>
</article>
