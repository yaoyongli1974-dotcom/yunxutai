<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$glyphs = ['photo_restore' => '修', 'memorial_video' => '影', 'life_article' => '述', 'family_archive' => '谱'];
$hasPhotos = $photos !== [];
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'ai']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">AI 工作室</p>
                <h1>把旧照片与零散记忆，整理成可以留下的东西</h1>
                <p class="muted" style="margin:0;">
                    AI 只做辅助整理，不替代家人的判断。生成结果默认仅成员可见，文章以草稿保存，核对后再发布。
                </p>
            </div>
            <span class="chip <?= $enabled ? 'chip--accent' : 'chip--warn' ?>"><?= e($mode) ?></span>
        </header>

        <?php if (!$enabled): ?>
            <div class="notice-card" style="margin-bottom:1.4rem;">
                <h2>AI 工作室尚未启用</h2>
                <p class="muted">请联系平台运营配置 AI 服务。未启用时不会产生任何生成任务或费用。</p>
            </div>
        <?php endif; ?>

        <div class="notice-card" style="margin-bottom:1.6rem;">
            <p style="margin:0;">
                <strong>先确认，再交给 AI。</strong>
                请只上传你有权使用的照片与资料；AI 生成内容可能出错，涉及逝者姓名、时间、地点与亲属关系的内容必须由家人逐项核对。
                当前展示价格为运营占位价，内测期不实际扣费。
            </p>
        </div>

        <div class="grid grid--2">
            <?php foreach ($services as $service): ?>
                <?php
                $slug = (string) $service['slug'];
                $glyph = $glyphs[$slug] ?? 'AI';
                ?>
                <article class="card ai-service-card" id="ai-<?= e($slug) ?>">
                    <div class="ai-service-card__head">
                        <span class="service-card__glyph" aria-hidden="true"><?= e($glyph) ?></span>
                        <div>
                            <h2><?= e($service['name']) ?></h2>
                            <p class="muted small" style="margin:.2rem 0 0;">
                                <?= e(format_price((int) $service['price_cents'])) ?> / <?= e($service['unit']) ?>
                            </p>
                        </div>
                    </div>
                    <p><?= e($service['description']) ?></p>
                    <p class="muted small"><?= e((string) ($service['price_note'] ?? '')) ?></p>

                    <?php if (!$enabled): ?>
                        <p class="muted small">启用后可在这里提交任务。</p>
                    <?php elseif ($slug === 'photo_restore'): ?>
                        <?php if (!$hasPhotos): ?>
                            <p class="muted small">还没有可用照片，请先在「影像与文件」上传照片。</p>
                        <?php else: ?>
                            <form class="form" method="post" action="<?= e(url($base . '/ai/jobs')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="service" value="photo_restore">
                                <label class="field">
                                    <span>选择原照片</span>
                                    <select name="media_id" required>
                                        <?php foreach ($photos as $photo): ?>
                                            <option value="<?= (int) $photo['id'] ?>">
                                                #<?= (int) $photo['id'] ?> · <?= e((string) ($photo['caption'] ?: $photo['original_name'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>成品说明（可选）</span>
                                    <input type="text" name="caption" maxlength="255" placeholder="例如：1968 年全家福，修复版">
                                </label>
                                <label class="field">
                                    <span>补充要求（可选）</span>
                                    <textarea name="notes" rows="2" maxlength="1200" placeholder="例如：保留旧照片的颗粒感，不要改变面部"></textarea>
                                </label>
                                <label class="checkbox">
                                    <input type="checkbox" name="consent" value="1" required>
                                    <span>我确认有权使用这张照片，并理解图片会发送至 AI 服务处理。</span>
                                </label>
                                <button class="button" type="submit">生成修复照片</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($slug === 'memorial_video'): ?>
                        <?php if (!$hasPhotos): ?>
                            <p class="muted small">还没有可用照片，请先上传至少一张照片。</p>
                        <?php else: ?>
                            <form class="form" method="post" action="<?= e(url($base . '/ai/jobs')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="service" value="memorial_video">
                                <fieldset class="field">
                                    <legend>选择照片（1–8 张）</legend>
                                    <div class="ai-photo-picker">
                                        <?php foreach (array_slice($photos, 0, 12) as $photo): ?>
                                            <label>
                                                <input type="checkbox" name="media_ids[]" value="<?= (int) $photo['id'] ?>">
                                                <img src="<?= e(url('/media/' . (int) $photo['id'])) ?>" alt="<?= e((string) ($photo['caption'] ?: '纪念照片')) ?>" loading="lazy">
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                                <label class="field">
                                    <span>希望的基调（可选）</span>
                                    <textarea name="notes" rows="2" maxlength="1200" placeholder="例如：安静一些，按时间顺序"></textarea>
                                </label>
                                <label class="checkbox">
                                    <input type="checkbox" name="consent" value="1" required>
                                    <span>我确认有权使用这些照片，并理解内容会发送至 AI 服务处理。</span>
                                </label>
                                <button class="button" type="submit">生成纪念短片</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($slug === 'life_article'): ?>
                        <form class="form" method="post" action="<?= e(url($base . '/ai/jobs')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="service" value="life_article">
                            <label class="field">
                                <span>文章标题（可选）</span>
                                <input type="text" name="title" maxlength="190" placeholder="例如：在讲台与书桌之间的一生">
                            </label>
                            <label class="field">
                                <span>希望补充或强调的内容（可选）</span>
                                <textarea name="notes" rows="3" maxlength="1200" placeholder="例如：他在西北工作的那几年，家人希望多写一些"></textarea>
                            </label>
                            <label class="checkbox">
                                <input type="checkbox" name="consent" value="1" required>
                                <span>我确认纪念馆资料有权用于整理，并理解内容会发送至 AI 服务处理。</span>
                            </label>
                            <button class="button" type="submit">生成生平草稿</button>
                        </form>
                    <?php elseif ($slug === 'family_archive'): ?>
                        <form class="form" method="post" action="<?= e(url($base . '/ai/jobs')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="service" value="family_archive">
                            <p class="muted small">
                                将收录：生平、<?= count($photos) ?> 张照片的缩略图、已发布文章与时间轴。生成后为可离线打开的 HTML 文件。
                            </p>
                            <label class="field">
                                <span>档案说明（可选）</span>
                                <textarea name="notes" rows="2" maxlength="1200" placeholder="例如：这是给下一代留存的版本，希望语气平实"></textarea>
                            </label>
                            <label class="checkbox">
                                <input type="checkbox" name="consent" value="1" required>
                                <span>我确认有权整理这些家族资料，并理解内容会发送至 AI 服务处理。</span>
                            </label>
                            <button class="button" type="submit">生成家族档案</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <section class="manage-section" style="margin-top:1.6rem;">
            <div class="manage-section__head">
                <div>
                    <h2>最近生成</h2>
                    <p class="muted">每天最多 <?= (int) $limit ?> 次；生成结果需由家人核对。</p>
                </div>
            </div>
            <?php if ($jobs === []): ?>
                <p class="muted">还没有生成记录。</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>服务</th><th>状态</th><th>结果</th><th>时间</th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <tr>
                                <td><?= e(ai_service_name((string) $job['service_slug'])) ?></td>
                                <td><span class="chip <?= $job['status'] === 'failed' ? 'chip--warn' : ($job['status'] === 'completed' ? 'chip--accent' : '') ?>"><?= e(ai_job_status_label((string) $job['status'])) ?></span></td>
                                <td><a href="<?= e(url($base . '/ai/jobs/' . (int) $job['id'])) ?>">查看 #<?= (int) $job['id'] ?></a></td>
                                <td class="muted"><?= e(format_datetime((string) $job['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
