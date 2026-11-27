<?php
/** @var array<string,mixed> $family */
/** @var array{persons:list<array<string,mixed>>,relations:list<array<string,mixed>>,truncated:bool} $tree */
/** @var list<array<string,mixed>> $relations */
/** @var list<array<string,mixed>> $branches */
/** @var array<string,array<string,mixed>> $relationTypes */
?>
<div class="shell" style="padding:2.5rem 0;">
    <p class="eyebrow">家族谱系</p><h1><?= e($family['name']) ?> · 谱系</h1>
    <?php if ($tree['truncated']): ?><div class="notice-card"><p>人物较多，已按限制截取部分节点，请按人物定位查看局部谱系。</p></div><?php endif; ?>

    <?php $graphData = json_encode([
        'persons' => $tree['persons'] ?? [],
        'relations' => $tree['relations'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    <section class="card">
        <div class="section-heading section-heading--row">
            <h2>可交互谱系图</h2>
            <div class="chip" style="display:flex;gap:0.3rem;">
                <button type="button" class="button button--small button--ghost" data-graph-action="zoom-in" aria-label="放大">＋</button>
                <button type="button" class="button button--small button--ghost" data-graph-action="zoom-out" aria-label="缩小">－</button>
                <button type="button" class="button button--small button--ghost" data-graph-action="reset">重置</button>
            </div>
        </div>
        <div id="genealogy-graph" data-tree="<?= e((string) $graphData) ?>"></div>
        <p class="muted small">拖动人物调整位置；在空白处拖动平移；滚轮或按钮缩放；触屏可单指拖动。数据按当前人物与层数按需加载。</p>
    </section>

    <section class="card">
        <h2>人物关系图</h2>
        <?php if ($tree['relations'] === []): ?><p class="muted">尚未有已确认的关系。提交关系并经管理员审核后显示在这里。</p><?php endif; ?>
        <ul>
        <?php foreach ($tree['relations'] as $rel): ?>
            <li><?= e($rel['person_a_name']) ?> → <?= e($relationTypes[$rel['relationship_type']]['label'] ?? $rel['relationship_type']) ?> → <?= e($rel['person_b_name']) ?></li>
        <?php endforeach; ?>
        </ul>
        <p class="small muted">当前显示 <?= count($tree['persons']) ?> 位人物、<?= count($tree['relations']) ?> 条已确认关系（按需加载）。</p>
    </section>

    <?php if ($canEdit): ?>
    <section class="card" style="margin-top:1rem;">
        <h2>提交关系候选</h2>
        <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/genealogy/relationships')) ?>">
            <?= csrf_field() ?>
            <label class="field">人物 A
                <select name="person_a_id"><?php foreach ($tree['persons'] as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field">关系（A 的视角）
                <select name="relationship_type"><?php foreach ($relationTypes as $key => $def): ?><option value="<?= e($key) ?>"><?= e($def['label']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field">人物 B
                <select name="person_b_id"><?php foreach ($tree['persons'] as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field">备注 <input name="note" maxlength="255"></label>
            <button class="button" type="submit">提交审核</button>
        </form>
    </section>

    <section class="card" style="margin-top:1rem;">
        <h2>新增支系</h2>
        <form class="form form--quiet" method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/genealogy/branches')) ?>">
            <?= csrf_field() ?>
            <label class="field">名称 <input name="name" required maxlength="190"></label>
            <label class="field">上级支系 ID（可空） <input name="parent_id" type="number" min="1"></label>
            <button class="button button--small" type="submit">创建支系</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card" style="margin-top:1rem;">
        <h2>关系与审核</h2>
        <table class="table"><thead><tr><th>关系</th><th>状态</th><th>操作</th></tr></thead><tbody>
        <?php foreach ($relations as $rel): ?>
            <tr>
                <td><?= e($rel['person_a_name']) ?> → <?= e($relationTypes[$rel['relationship_type']]['label'] ?? $rel['relationship_type']) ?> → <?= e($rel['person_b_name']) ?></td>
                <td><?= e($rel['status']) ?></td>
                <td>
                    <?php if ($canReview && in_array($rel['status'], ['pending', 'disputed'], true)): ?>
                        <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/genealogy/relationships/' . (int) $rel['id'] . '/review')) ?>" style="display:inline;">
                            <?= csrf_field() ?><input type="hidden" name="status" value="confirmed">
                            <button class="button button--small" type="submit">确认</button>
                        </form>
                        <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/genealogy/relationships/' . (int) $rel['id'] . '/review')) ?>" style="display:inline;">
                            <?= csrf_field() ?><input type="hidden" name="status" value="disputed">
                            <button class="button button--small button--ghost" type="submit">标记争议</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($rel['status'], ['pending', 'confirmed', 'disputed'], true)): ?>
                        <form method="post" action="<?= e(url('/families/' . (int) $family['id'] . '/genealogy/relationships/' . (int) $rel['id'] . '/revoke')) ?>" style="display:inline;">
                            <?= csrf_field() ?><button class="button button--small button--ghost" type="submit">撤销</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
</div>
<script src="<?= e(url('assets/js/genealogy.js')) ?>" defer></script>
