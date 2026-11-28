<article>
    <div class="shell">
        <header class="dash-head">
            <div>
                <p class="eyebrow">我的纪念馆</p>
                <h1><?= e($user['display_name']) ?>，你在这里保管着 <?= count($memorials) ?> 座纪念馆</h1>
                <p class="dash-head__greet">每一座馆都可由家人共同维护。右侧标示角色：馆主可整体管理，协作者可编辑内容，亲友可浏览私密馆。</p>
            </div>
            <a class="button" href="<?= e(url('/manage/memorials/new')) ?>">为亲人创建纪念馆</a>
        </header>

        <?php if ($memorials === []): ?>
            <?php $onboardingKey = 'yxt-onboarding-dismissed-' . (int) ($user['id'] ?? 0); ?>
            <aside class="notice-card onboarding" data-onboarding data-onboarding-key="<?= e($onboardingKey) ?>" aria-label="新手引导">
                <div class="onboarding__head">
                    <div>
                        <p class="eyebrow">从零开始</p>
                        <h2 class="onboarding__title">五步，把回忆安放好</h2>
                    </div>
                    <button class="button button--small button--ghost onboarding__skip" type="button" data-onboarding-dismiss>跳过引导</button>
                </div>
                <ol class="onboarding__list">
                    <li class="onboarding__step">
                        <span class="onboarding__num" aria-hidden="true">1</span>
                        <span><a class="text-link" href="<?= e(url('/manage/memorials/new')) ?>">创建纪念馆</a><span class="onboarding__hint">免费，默认私密</span></span>
                    </li>
                    <li class="onboarding__step">
                        <span class="onboarding__num" aria-hidden="true">2</span>
                        <span><span class="onboarding__label">添加纪念人物</span><span class="onboarding__hint">填写逝者信息与生平</span></span>
                    </li>
                    <li class="onboarding__step">
                        <span class="onboarding__num" aria-hidden="true">3</span>
                        <span><span class="onboarding__label">上传第一批资料</span><span class="onboarding__hint">照片、声音、文字与文件</span></span>
                    </li>
                    <li class="onboarding__step">
                        <span class="onboarding__num" aria-hidden="true">4</span>
                        <span><span class="onboarding__label">AI 整理（可跳过）</span><span class="onboarding__hint">生成生平草稿，随时可跳过</span></span>
                    </li>
                    <li class="onboarding__step">
                        <span class="onboarding__num" aria-hidden="true">5</span>
                        <span><span class="onboarding__label">邀请家人</span><span class="onboarding__hint">由馆主发出链接，家人主动接受</span></span>
                    </li>
                </ol>
            </aside>
        <?php endif; ?>

        <div class="section--tight" style="padding-bottom:3.5rem;">
            <?php if ($memorials !== []): ?>
                <div class="mem-admin-list">
                    <?php foreach ($memorials as $memorial): ?>
                        <?php $memberRole = (string) $memorial['member_role']; ?>
                        <div class="mem-admin-card">
                            <span class="mem-admin-card__glyph" aria-hidden="true"><?= e(mb_substr((string) $memorial['full_name'], 0, 1)) ?></span>
                            <div class="mem-admin-card__body">
                                <h3>
                                    <a href="<?= e(url('/m/' . $memorial['slug'])) ?>" style="text-decoration:none;color:inherit;"><?= e($memorial['title']) ?></a>
                                </h3>
                                <p class="mem-admin-card__meta">
                                    逝者：<?= e((string) $memorial['full_name']) ?>
                                    <?php if (life_dates($memorial) !== ''): ?> · <?= e(life_dates($memorial)) ?><?php endif; ?>
                                </p>
                            </div>
                            <div class="mem-admin-card__actions">
                                <span class="chip chip--accent"><?= e(role_label($memberRole)) ?></span>
                                <?php if (in_array($memberRole, ['owner', 'editor'], true)): ?>
                                    <a class="button button--small" href="<?= e(url('/manage/memorials/' . (int) $memorial['id'])) ?>">管理</a>
                                <?php endif; ?>
                                <a class="button button--small button--ghost" href="<?= e(url('/m/' . $memorial['slug'])) ?>">查看公开页</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php App\Core\View::partial('partials/empty-state', [
                    'title' => '还没有你参与的纪念馆',
                    'text' => '免费的。创建后可邀请家人一起编辑。',
                    'glyph' => '园',
                    'action' => ['href' => url('/manage/memorials/new'), 'label' => '为亲人创建纪念馆'],
                ]); ?>
            <?php endif; ?>

            <hr class="divider">
            <div class="split" style="align-items:flex-start;">
                <div>
                    <p class="eyebrow">家人邀请</p>
                    <h3 style="font-size:1.05rem;">收到了来自馆主的邀请链接？</h3>
                    <p class="small muted">打开那条邀请链接即可加入。这里的合作都必须由已登录的账号主动接受，平台不会替你加入任何馆。</p>
                </div>
                <div>
                    <p class="eyebrow">账号与数据</p>
                    <h3 style="font-size:1.05rem;">改名、改密码与数据权利</h3>
                    <p class="small muted">资料导出、删除或撤回授权等申请，在<a href="<?= e(url('/account')) ?>">账号设置</a>里提交，平台会人工核验后处理。</p>
                    <p class="small muted" style="margin:0;"><a class="button button--small button--ghost" href="<?= e(url('/account')) ?>">进入账号设置</a></p>
                </div>
            </div>
        </div>
    </div>
</article>
