<?php
use App\Core\View;

$base = '/manage/memorials/' . (int) $memorial['id'];
$createOld = ($editEventId === null && $errors !== []) ? $old : ['sort_order' => '0'];
$createValue = static fn (string $key, string $default = ''): string => (string) ($createOld[$key] ?? $default);
?>
<div class="shell manage-shell">
    <?php View::partial('manage/_nav', ['memorial' => $memorial, 'active' => 'life']); ?>

    <div class="manage-main">
        <header class="manage-head">
            <div>
                <p class="eyebrow">生平</p>
                <h1>一条一条，记下走过的路</h1>
                <p class="muted" style="margin:0;">日期可以不完整，用年份说明代替。排序值越小越靠前。</p>
            </div>
        </header>

        <?php if (!empty($errors['form'])): ?>
            <div class="form-error" role="alert"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <section class="manage-section">
            <h2>添加生平事件</h2>
            <form class="form manage-form manage-form--grid" method="post" action="<?= e(url($base . '/life')) ?>">
                <?= csrf_field() ?>
                <label class="field field--wide">
                    <span>事件标题</span>
                    <input type="text" name="title" value="<?= e($createValue('title')) ?>" maxlength="190" required placeholder="例如：出生于苏州">
                    <?php if ($editEventId === null && !empty($errors['title'])): ?><small class="field__error"><?= e($errors['title']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>日期（可选）</span>
                    <input type="date" name="event_date" value="<?= e($createValue('event_date')) ?>">
                    <?php if ($editEventId === null && !empty($errors['event_date'])): ?><small class="field__error"><?= e($errors['event_date']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>年份说明（可选）</span>
                    <input type="text" name="year_label" value="<?= e($createValue('year_label')) ?>" maxlength="40" placeholder="例如：1938 年前后">
                    <?php if ($editEventId === null && !empty($errors['year_label'])): ?><small class="field__error"><?= e($errors['year_label']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>地点（可选）</span>
                    <input type="text" name="location" value="<?= e($createValue('location')) ?>" maxlength="190">
                    <?php if ($editEventId === null && !empty($errors['location'])): ?><small class="field__error"><?= e($errors['location']) ?></small><?php endif; ?>
                </label>
                <label class="field">
                    <span>排序（可选）</span>
                    <input type="number" name="sort_order" value="<?= e($createValue('sort_order', '0')) ?>" step="1">
                    <small class="field__hint">数字越小越靠前。</small>
                    <?php if ($editEventId === null && !empty($errors['sort_order'])): ?><small class="field__error"><?= e($errors['sort_order']) ?></small><?php endif; ?>
                </label>
                <label class="field field--wide">
                    <span>事件叙述（可选）</span>
                    <textarea name="description" maxlength="5000" style="min-height:100px;"><?= e($createValue('description')) ?></textarea>
                    <?php if ($editEventId === null && !empty($errors['description'])): ?><small class="field__error"><?= e($errors['description']) ?></small><?php endif; ?>
                </label>
                <div class="field--wide">
                    <button class="button button--small" type="submit">添加事件</button>
                </div>
            </form>
        </section>

        <section class="manage-section">
            <h2>已记录的生平</h2>
            <?php if ($events === []): ?>
                <p class="manage-empty">还没有生平记录。可以从出生、求学、工作、家庭与晚年依次写起。</p>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <?php
                    $isEditing = $editEventId === (int) $event['id'];
                    $values = $isEditing ? $old : $event;
                    $value = static fn (string $key, string $default = ''): string => (string) ($values[$key] ?? $default);
                    $dateText = format_date($event['event_date'] ?? null, 'Y年n月j日');
                    if ($dateText === '') {
                        $dateText = (string) ($event['year_label'] ?? '');
                    }
                    ?>
                    <details class="manage-event"<?= $isEditing ? ' open' : '' ?>>
                        <summary class="manage-event__summary">
                            <?php if ($dateText !== ''): ?><span class="manage-pill"><?= e($dateText) ?></span><?php endif; ?>
                            <strong><?= e($event['title']) ?></strong>
                            <?php if (!empty($event['location'])): ?><span class="muted"> · <?= e($event['location']) ?></span><?php endif; ?>
                        </summary>
                        <?php if (!empty($event['description'])): ?>
                            <p class="manage-content muted"><?= e($event['description']) ?></p>
                        <?php endif; ?>

                        <form class="form manage-form manage-form--grid" method="post" action="<?= e(url($base . '/life/' . (int) $event['id'] . '/update')) ?>" style="margin-top:0.8rem;">
                            <?= csrf_field() ?>
                            <label class="field field--wide">
                                <span>事件标题</span>
                                <input type="text" name="title" value="<?= e($value('title')) ?>" maxlength="190" required>
                                <?php if ($isEditing && !empty($errors['title'])): ?><small class="field__error"><?= e($errors['title']) ?></small><?php endif; ?>
                            </label>
                            <label class="field">
                                <span>日期（可选）</span>
                                <input type="date" name="event_date" value="<?= e($value('event_date')) ?>">
                                <?php if ($isEditing && !empty($errors['event_date'])): ?><small class="field__error"><?= e($errors['event_date']) ?></small><?php endif; ?>
                            </label>
                            <label class="field">
                                <span>年份说明（可选）</span>
                                <input type="text" name="year_label" value="<?= e($value('year_label')) ?>" maxlength="40">
                                <?php if ($isEditing && !empty($errors['year_label'])): ?><small class="field__error"><?= e($errors['year_label']) ?></small><?php endif; ?>
                            </label>
                            <label class="field">
                                <span>地点（可选）</span>
                                <input type="text" name="location" value="<?= e($value('location')) ?>" maxlength="190">
                                <?php if ($isEditing && !empty($errors['location'])): ?><small class="field__error"><?= e($errors['location']) ?></small><?php endif; ?>
                            </label>
                            <label class="field">
                                <span>排序（可选）</span>
                                <input type="number" name="sort_order" value="<?= e($value('sort_order', '0')) ?>" step="1">
                                <?php if ($isEditing && !empty($errors['sort_order'])): ?><small class="field__error"><?= e($errors['sort_order']) ?></small><?php endif; ?>
                            </label>
                            <label class="field field--wide">
                                <span>事件叙述（可选）</span>
                                <textarea name="description" maxlength="5000" style="min-height:90px;"><?= e($value('description')) ?></textarea>
                                <?php if ($isEditing && !empty($errors['description'])): ?><small class="field__error"><?= e($errors['description']) ?></small><?php endif; ?>
                            </label>
                            <div class="field--wide manage-actions-row">
                                <button class="button button--small" type="submit">保存修改</button>
                            </div>
                        </form>

                        <form method="post" action="<?= e(url($base . '/life/' . (int) $event['id'] . '/delete')) ?>" data-confirm="确认删除这条生平记录？删除后不可恢复。">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit">删除这条记录</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
