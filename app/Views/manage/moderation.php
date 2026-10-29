<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$groups = [
    'pending' => '待审核',
    'visible' => '已展示',
    'hidden' => '已隐藏',
];
$messageCounts = [
    'pending' => count($messages['pending']),
    'visible' => count($messages['visible']),
    'hidden' => count($messages['hidden']),
];
$tributeCounts = [
    'pending' => count($tributes['pending']),
    'visible' => count($tributes['visible']),
    'hidden' => count($tributes['hidden']),
];
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'moderation']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">内容审核</p>
                <h1>留言与祭奠</h1>
                <p class="muted" style="margin:0;">
                    待审核留言 <?= (int) $messageCounts['pending'] ?> 条。隐藏的内容不会出现在公开页面，但记录仍保留。
                </p>
            </div>
        </header>

        <?php if (!empty($errors['status'])): ?>
            <div class="form-error" role="alert"><?= e($errors['status']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>留言</h2>
            <?php foreach ($groups as $groupKey => $groupLabel): ?>
                <h3 style="margin:1rem 0 0.4rem;"><?= e($groupLabel) ?>（<?= (int) $messageCounts[$groupKey] ?>）</h3>
                <?php if ($messages[$groupKey] === []): ?>
                    <p class="manage-empty"><?= $groupKey === 'pending' ? '暂无待审核留言。' : '没有记录。' ?></p>
                <?php else: ?>
                    <table class="manage-table">
                        <thead>
                        <tr>
                            <th>留言</th>
                            <th>访客</th>
                            <th>时间</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($messages[$groupKey] as $message): ?>
                            <tr>
                                <td class="manage-content" style="max-width:30rem;"><?= e($message['content']) ?></td>
                                <td><?= e($message['author_name']) ?></td>
                                <td><?= e(format_datetime($message['created_at'])) ?></td>
                                <td><span class="manage-badge manage-badge--<?= e((string) $message['status']) ?>"><?= e(status_label((string) $message['status'])) ?></span></td>
                                <td>
                                    <div class="manage-table__actions">
                                        <?php if ($message['status'] !== 'visible'): ?>
                                            <form method="post" action="<?= e(url($base . '/moderation/messages/' . (int) $message['id'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="visible">
                                                <button class="button button--small button--ghost" type="submit">通过展示</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($message['status'] !== 'hidden'): ?>
                                            <form method="post" action="<?= e(url($base . '/moderation/messages/' . (int) $message['id'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="hidden">
                                                <button class="button button--small button--danger" type="submit">隐藏</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>

        <section class="manage-section">
            <h2>祭奠记录</h2>
            <p class="manage-note">祭奠记录只保留献花、点烛、敬香与鞠躬，不涉及积分或消费。</p>
            <?php foreach ($groups as $groupKey => $groupLabel): ?>
                <h3 style="margin:1rem 0 0.4rem;"><?= e($groupLabel) ?>（<?= (int) $tributeCounts[$groupKey] ?>）</h3>
                <?php if ($tributes[$groupKey] === []): ?>
                    <p class="manage-empty"><?= $groupKey === 'pending' ? '暂无待审核祭奠记录。' : '没有记录。' ?></p>
                <?php else: ?>
                    <table class="manage-table">
                        <thead>
                        <tr>
                            <th>祭奠</th>
                            <th>亲友</th>
                            <th>寄语</th>
                            <th>时间</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tributes[$groupKey] as $tribute): ?>
                            <tr>
                                <td><?= e(tribute_label((string) $tribute['type'])) ?></td>
                                <td><?= e(!empty($tribute['is_anonymous']) ? '一位访客' : ($tribute['display_name'] ?: '访客')) ?></td>
                                <td class="manage-content"><?= e($tribute['note'] ?: '—') ?></td>
                                <td><?= e(format_datetime($tribute['created_at'])) ?></td>
                                <td><span class="manage-badge manage-badge--<?= e((string) $tribute['status']) ?>"><?= e(status_label((string) $tribute['status'])) ?></span></td>
                                <td>
                                    <div class="manage-table__actions">
                                        <?php if ($tribute['status'] !== 'visible'): ?>
                                            <form method="post" action="<?= e(url($base . '/moderation/tributes/' . (int) $tribute['id'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="visible">
                                                <button class="button button--small button--ghost" type="submit">通过展示</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($tribute['status'] !== 'hidden'): ?>
                                            <form method="post" action="<?= e(url($base . '/moderation/tributes/' . (int) $tribute['id'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="status" value="hidden">
                                                <button class="button button--small button--danger" type="submit">隐藏</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    </div>
</div>
