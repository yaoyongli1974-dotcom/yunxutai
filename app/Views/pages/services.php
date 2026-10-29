<article>
    <div class="shell page-head">
        <p class="eyebrow">长期纪念服务</p>
        <h1>把家中的记忆，慢慢整理成形</h1>
        <p class="page-head__meta">每一项都是具体可交付的工作，明码标价；不卖虚拟祭品，不做任何排名。</p>
    </div>

    <section class="section">
        <div class="shell">
            <div class="grid grid--2">
                <article class="card service-card">
                    <span class="service-card__glyph" aria-hidden="true">修</span>
                    <h3>老照片修复</h3>
                    <p>破损、发黄、模糊的纸质照片，逐张修复或数码翻新。先出小样，确认后交付原图与成品文件，不满意不收尾款。</p>
                    <p class="service-card__price">参考价 80–300 元/张，按破损程度与张数计价；先出样稿，确认后再收尾款。</p>
                </article>
                <article class="card service-card">
                    <span class="service-card__glyph" aria-hidden="true">影</span>
                    <h3>纪念视频</h3>
                    <p>把照片、旧影像与家人的口述剪成一部安静的生活回顾短片，用于灵堂、追思会或家人间流传。</p>
                    <p class="service-card__price">参考价 600–3000 元/支，按成片时长、素材数量与配音方式计价；分镜确认后开工。</p>
                </article>
                <article class="card service-card">
                    <span class="service-card__glyph" aria-hidden="true">述</span>
                    <h3>生平文章整理</h3>
                    <p>以访谈和旧材料为底本，把零散记忆整理成生平文稿，可直接放入纪念馆，也适合作为家传文稿留存。</p>
                    <p class="service-card__price">参考价 300–1200 元/篇，按访谈次数与字数计价；包含一次修改。</p>
                </article>
                <article class="card service-card">
                    <span class="service-card__glyph" aria-hidden="true">谱</span>
                    <h3>家族数字档案</h3>
                    <p>家族成员名录与关系、迁徙与大事年表、老照片与口述史的数字化归档与保存。</p>
                    <p class="service-card__price">参考价 1500 元起/族卷，按人数、资料量与数字化工作量逐项列明。</p>
                </article>
            </div>
            <article class="card service-card" style="margin-top:1.3rem;">
                <span class="service-card__glyph" aria-hidden="true">园</span>
                <h3>机构版平台</h3>
                <p>面向殡葬服务机构与纪念园区：品牌化的纪念页面、机构管理后台与数字档案服务。合作开始前会书面确认双方资质与边界。</p>
                <p class="service-card__price">按机构规模与功能范围书面报价，不按“香火”或祭奠次数分成。</p>
            </article>
        </div>
    </section>

    <section class="section section--tint">
        <div class="shell">
            <div class="page-head" style="padding:0 0 1.4rem;">
                <p class="eyebrow">AI 自助工作室 · 内测</p>
                <h2>馆主可以自己动手整理</h2>
                <p class="muted">在纪念馆管理后台使用。AI 只做辅助：生成结果先给家人核对，默认仅成员可见；当前价格为运营占位价，内测期不实际扣费。</p>
            </div>
            <div class="grid grid--4">
                <?php foreach (($aiServices ?? []) as $service): ?>
                    <article class="card service-card">
                        <span class="service-card__glyph" aria-hidden="true">
                            <?= e(['photo_restore' => '修', 'memorial_video' => '影', 'life_article' => '述', 'family_archive' => '谱'][$service['slug']] ?? 'AI') ?>
                        </span>
                        <h3><?= e($service['name']) ?></h3>
                        <p><?= e($service['description']) ?></p>
                        <p class="service-card__price">
                            <?= e(format_price((int) $service['price_cents'])) ?> / <?= e($service['unit']) ?>
                            <span class="muted small">（<?= e((string) ($service['price_note'] ?? '占位价')) ?>）</span>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
            <p style="margin-top:1.4rem;">
                <a class="button button--ghost" href="<?= e(url('/dashboard')) ?>">进入我的纪念馆使用</a>
                <span class="muted small">只有馆主可以发起生成；协作者可查看成员可见的成果。</span>
            </p>
        </div>
    </section>

    <section class="section section--tint">
        <div class="shell report-shell">
            <div>
                <p class="eyebrow">咨询预约</p>
                <h2>说说你想做的事</h2>
                <p class="small muted" style="margin-bottom:1.4rem;">
                    填写后会由工作人员在工作日与你联系。我们不会自动扣费，更不会给你推销任何增值香火。
                    如果暂时不确定想要什么，选「其他」写清楚即可，可请我们出主意。
                </p>

                <?php if (!empty($errors['form'])): ?>
                    <div class="form-error" role="alert" style="margin-bottom:1.2rem;">
                        <p><?= e($errors['form']) ?></p>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= e(url('/services')) ?>" style="max-width:34rem;">
                    <?= csrf_field() ?>
                    <div class="hp" aria-hidden="true">
                        <!-- 人类不应填写，仅作蜜罐 -->
                        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>
                    <label class="field">
                        <span>称呼</span>
                        <input type="text" name="name" value="<?= e($old['name'] ?? '') ?>" maxlength="80" required>
                        <?php if (!empty($errors['name'])): ?><small class="field__error"><?= e($errors['name']) ?></small><?php endif; ?>
                    </label>
                    <label class="field">
                        <span>联系方式（手机号或邮箱）</span>
                        <input type="text" name="contact" value="<?= e($old['contact'] ?? '') ?>" maxlength="190" required>
                        <?php if (!empty($errors['contact'])): ?><small class="field__error"><?= e($errors['contact']) ?></small><?php endif; ?>
                    </label>
                    <label class="field">
                        <span>想咨询的服务</span>
                        <select name="service_type" required>
                            <option value="">请选择…</option>
                            <option value="photo_restore" <?= ($old['service_type'] ?? '') === 'photo_restore' ? 'selected' : '' ?>>老照片修复</option>
                            <option value="memorial_video" <?= ($old['service_type'] ?? '') === 'memorial_video' ? 'selected' : '' ?>>纪念视频</option>
                            <option value="life_archive" <?= ($old['service_type'] ?? '') === 'life_archive' ? 'selected' : '' ?>>生平文章整理</option>
                            <option value="family_archive" <?= ($old['service_type'] ?? '') === 'family_archive' ? 'selected' : '' ?>>家族数字档案</option>
                            <option value="institution" <?= ($old['service_type'] ?? '') === 'institution' ? 'selected' : '' ?>>机构版平台与机构常态合作</option>
                            <option value="other" <?= ($old['service_type'] ?? '') === 'other' ? 'selected' : '' ?>>其他</option>
                        </select>
                        <?php if (!empty($errors['service_type'])): ?><small class="field__error"><?= e($errors['service_type']) ?></small><?php endif; ?>
                    </label>
                    <label class="field">
                        <span>想做的事（选填，2000 字以内）</span>
                        <textarea name="message" rows="5" maxlength="2000" placeholder="比如：想把太爷爷的老照片翻拍修复，并且补充一段生平。"><?= e($old['message'] ?? '') ?></textarea>
                        <?php if (!empty($errors['message'])): ?><small class="field__error"><?= e($errors['message']) ?></small><?php endif; ?>
                    </label>
                    <div class="form__actions">
                        <button class="button" type="submit">提交咨询</button>
                        <span class="form__foot">提交即表示同意仅以联系为目的处理你的信息。</span>
                    </div>
                </form>
            </div>

            <aside class="report-aside">
                <h3>关于收费的原则</h3>
                <ul>
                    <li>建馆、祭奠、留言永久免费，不以次数、年限或“会员等级”限制基础纪念；</li>
                    <li>付费服务在开工前给出书面报价，列明交付物、周期与修改次数；确认后才付款；</li>
                    <li>未开工可全额退款；已开工按已完成进度结算，不收取未事先说明的费用；</li>
                    <li>不自动续费、不默认勾选；不卖虚拟祭品，不设香火值、不排贡献榜；</li>
                    <li>如果你认为某项收费不清楚，请先别付款，随时联系平台。</li>
                </ul>
                <h3 style="margin-top:1.4rem;">数据保障</h3>
                <p class="muted small">服务交付物仅用于你与家人；如涉及照片修复，成品之外的底片与生成过程材料在交付后 60 天删除。详见<a href="<?= e(url('/privacy')) ?>">隐私与数据保护</a>。</p>
            </aside>
        </div>
    </section>
</article>
