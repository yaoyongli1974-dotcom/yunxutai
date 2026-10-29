<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$kindValue = (string) ($old['kind'] ?? 'photo');
$visibilityValue = (string) ($old['visibility'] ?? 'visitors');
$captionValue = (string) ($old['caption'] ?? '');
$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
};
$coverId = (int) ($memorial['cover_media_id'] ?? 0);
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'media']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">影像与文件</p>
                <h1>照片、声音与文字档案</h1>
                <p class="muted" style="margin:0;">文件存放在站点目录之外，访客只能通过鉴权地址查看。</p>
            </div>
            <a class="button button--small button--ghost" href="<?= e(url('/m/' . $memorial['slug'] . '/gallery')) ?>">查看公开影像</a>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>上传文件</h2>
            <p class="manage-note" style="margin-top:-0.4rem;">
                单个文件不超过 <?= e((string) $maxMb) ?> MB，视频不超过 <?= e((string) $videoMb) ?> MB。
                支持 JPG、PNG、WebP、GIF 图片，MP4 视频，MP3、M4A 音频与 PDF 文档。
            </p>

            <form class="form manage-form manage-form--grid" method="post" action="<?= e(url($base . '/media')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label class="field">
                    <span>文件分类</span>
                    <select name="kind">
                        <option value="photo"<?= $kindValue === 'photo' ? ' selected' : '' ?>>照片</option>
                        <option value="video"<?= $kindValue === 'video' ? ' selected' : '' ?>>视频</option>
                        <option value="audio"<?= $kindValue === 'audio' ? ' selected' : '' ?>>音频</option>
                        <option value="document"<?= $kindValue === 'document' ? ' selected' : '' ?>>文档</option>
                    </select>
                    <small class="field__hint">文件实际类型必须与分类一致。</small>
                    <?php if (!empty($errors['kind'])): ?><small class="field__error"><?= e($errors['kind']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>可见范围</span>
                    <select name="visibility">
                        <option value="visitors"<?= $visibilityValue === 'visitors' ? ' selected' : '' ?>>访客可见</option>
                        <option value="members"<?= $visibilityValue === 'members' ? ' selected' : '' ?>>仅成员可见</option>
                    </select>
                    <small class="field__hint">“仅成员”文件需要登录且为纪念馆成员才能打开。</small>
                    <?php if (!empty($errors['visibility'])): ?><small class="field__error"><?= e($errors['visibility']) ?></small><?php endif; ?>
                </label>
                <label class="field field--wide">
                    <span>选择文件</span>
                    <input type="file" name="file" required accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,audio/mpeg,audio/mp4,application/pdf">
                    <?php if (!empty($errors['file'])): ?><small class="field__error"><?= e($errors['file']) ?></small><?php endif; ?>
                </label>
                <label class="field field--wide">
                    <span>说明文字（可选）</span>
                    <input type="text" name="caption" value="<?= e($captionValue) ?>" maxlength="255" placeholder="例如：1985 年夏，在苏州老宅">
                    <?php if (!empty($errors['caption'])): ?><small class="field__error"><?= e($errors['caption']) ?></small><?php endif; ?>
                </label>
                <div class="field--wide">
                    <button class="button" type="submit">上传文件</button>
                </div>
            </form>
        </section>

        <section class="manage-section">
            <h2>全部文件</h2>
            <p class="manage-note" style="margin-top:-0.4rem;">封面照片需为“访客可见”的展示中照片。</p>
            <?php if ($media === []): ?>
                <p class="manage-empty">还没有上传文件。可以先从一张清晰的老照片开始。</p>
            <?php else: ?>
                <table class="manage-table">
                    <thead>
                    <tr>
                        <th>预览</th>
                        <th>文件</th>
                        <th>分类</th>
                        <th>可见范围</th>
                        <th>状态</th>
                        <th>大小</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($media as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item['kind'] === 'photo' && $item['status'] === 'active'): ?>
                                    <img class="manage-thumb" src="<?= e(url('/media/' . (int) $item['id'])) ?>" alt="<?= e($item['caption'] ?: $item['original_name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="manage-thumb manage-thumb--empty"><?= e($item['status'] === 'hidden' ? '已隐藏' : media_kind_label((string) $item['kind'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($item['caption'] ?: $item['original_name']) ?>
                                <div class="manage-note"><?= e($item['original_name']) ?></div>
                                <?php if ($coverId === (int) $item['id']): ?>
                                    <span class="manage-pill">当前封面</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(media_kind_label((string) $item['kind'])) ?></td>
                            <td><?= e($item['visibility'] === 'members' ? '仅成员' : '访客') ?></td>
                            <td><span class="manage-badge manage-badge--<?= e((string) $item['status']) ?>"><?= e($item['status'] === 'hidden' ? '已隐藏' : '展示中') ?></span></td>
                            <td><?= e($formatSize((int) $item['size_bytes'])) ?></td>
                            <td>
                                <div class="manage-table__actions">
                                    <?php if ($item['status'] === 'active'): ?>
                                        <form method="post" action="<?= e(url($base . '/media/' . (int) $item['id'] . '/hide')) ?>" data-confirm="隐藏后访客将无法看到这个文件。确认隐藏？">
                                            <?= csrf_field() ?>
                                            <button class="button button--small button--ghost" type="submit">隐藏</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(url($base . '/media/' . (int) $item['id'] . '/show')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="button button--small button--ghost" type="submit">恢复</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($item['kind'] === 'photo' && $item['status'] === 'active' && $item['visibility'] === 'visitors' && $coverId !== (int) $item['id']): ?>
                                        <form method="post" action="<?= e(url($base . '/media/' . (int) $item['id'] . '/cover')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="button button--small button--ghost" type="submit">设为封面</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url($base . '/media/' . (int) $item['id'] . '/visibility')) ?>" data-confirm="<?= $item['visibility'] === 'members' ? '改为访客可见后，任何访问纪念馆的人都能打开这个文件。确认公开？' : '改为仅成员后，访客将无法打开这个文件。确认？' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="visibility" value="<?= $item['visibility'] === 'members' ? 'visitors' : 'members' ?>">
                                        <button class="button button--small button--ghost" type="submit"><?= $item['visibility'] === 'members' ? '对访客公开' : '仅成员可见' ?></button>
                                    </form>
                                    <form method="post" action="<?= e(url($base . '/media/' . (int) $item['id'] . '/delete')) ?>" data-confirm="确认删除这个文件？文件将从存储中移除，操作不可恢复。">
                                        <?= csrf_field() ?>
                                        <button class="button button--small button--danger" type="submit">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
