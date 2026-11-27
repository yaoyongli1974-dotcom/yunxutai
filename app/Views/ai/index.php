<?php
use App\Core\Auth;
use App\Core\Policy;
use App\Services\AiWorkflowService;
/** @var array<string,mixed> $family */
/** @var list<array<string,mixed>> $workflows */
/** @var list<array<string,mixed>> $facts */
/** @var list<array<string,mixed>> $sessions */
/** @var list<array<string,mixed>> $logs */
/** @var array<string,array{0:string,1:list<string>}> $types */
$familyId = (int) $family['id'];
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">AI 家族记忆工坊</p><h1><?= e($family['name']) ?> · 记忆整理</h1></div>
        <a class="button button--ghost button--small" href="<?= e(url('/families/' . $familyId)) ?>">返回家族</a>
    </div>

    <div class="notice-card">
        <p><strong>AI 只做整理，不做认定。</strong>所有输出均为草稿或候选事实，必须经家族成员人工审核；AI 不得自动建立亲属关系、不得将推测写成正式史料。涉及受访者授权的内容，授权撤回后不可继续处理。</p>
    </div>

    <?php if (Policy::can('ai.workflow.create', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
    <section class="section section--tight">
        <h2>新建整理任务</h2>
        <form class="form" method="post" action="<?= e(url('/families/' . $familyId . '/ai/workflows')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="type">任务类型</label>
                <select id="type" name="type">
                    <?php foreach ($types as $slug => $definition): ?>
                        <option value="<?= e($slug) ?>"><?= e($definition[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="text">文字资料（仅使用你有权访问的内容）</label>
                <textarea id="text" name="text" rows="6" placeholder="粘贴档案文字、已校正的转写稿或家属提供的资料"></textarea>
            </div>
            <div class="field">
                <label for="idempotency_key">幂等键（可选，重复提交同一键不会新建任务）</label>
                <input id="idempotency_key" name="idempotency_key" maxlength="100">
            </div>
            <button class="button" type="submit">创建任务</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="section section--tight">
        <h2>任务队列</h2>
        <?php if ($workflows === []): ?><div class="empty"><p>还没有 AI 任务。</p></div><?php endif; ?>
        <table class="table">
            <?php foreach ($workflows as $workflow): ?>
                <tr>
                    <td><a class="text-link" href="<?= e(url('/families/' . $familyId . '/ai/workflows/' . (int) $workflow['id'])) ?>">#<?= (int) $workflow['id'] ?> <?= e($types[$workflow['workflow_type']][0] ?? $workflow['workflow_type']) ?></a></td>
                    <td><?= e($workflow['status']) ?></td>
                    <td class="muted small"><?= e($workflow['current_step'] ?? '') ?> · 重试 <?= (int) $workflow['retry_count'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="section section--tight">
        <h2>候选事实（待人工确认）</h2>
        <?php if ($facts === []): ?><div class="empty"><p>暂无候选事实。</p></div><?php endif; ?>
        <?php foreach ($facts as $fact): ?>
            <div class="card" style="margin-bottom:.75rem;">
                <p class="small muted"><?= e($fact['source_type']) ?> · <?= e($fact['verification_status']) ?> · 置信度 <?= e(number_format((float) $fact['confidence'], 2)) ?></p>
                <p><?= e($fact['fact_content']) ?></p>
                <?php if (Policy::can('ai.result.review', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
                    <form method="post" action="<?= e(url('/ai/facts/' . (int) $fact['id'] . '/review')) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="confirmed"><button class="button button--small" type="submit">确认</button>
                    </form>
                    <form method="post" action="<?= e(url('/ai/facts/' . (int) $fact['id'] . '/review')) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="disputed"><button class="button button--ghost button--small" type="submit">标记争议</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="section section--tight">
        <h2>口述历史</h2>
        <?php if (Policy::can('ai.workflow.create', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
        <form class="form" method="post" action="<?= e(url('/families/' . $familyId . '/ai/oral-sessions')) ?>">
            <?= csrf_field() ?>
            <div class="field"><label for="title">采访标题</label><input id="title" name="title" required></div>
            <div class="field"><label for="topic">主题</label><input id="topic" name="topic" placeholder="童年经历 / 家族迁徙 / 重要人生事件"></div>
            <div class="field"><label for="interviewer">采访者</label><input id="interviewer" name="interviewer"></div>
            <button class="button button--small" type="submit">创建采访</button>
        </form>
        <?php endif; ?>
        <?php foreach ($sessions as $session): ?>
            <div class="card" style="margin-top:.75rem;">
                <h3><?= e($session['title']) ?></h3>
                <p class="small muted">授权：<?= e($session['consent_status']) ?> · 处理：<?= e($session['processing_status']) ?></p>
                <?php if (Policy::can('oral_history.consent.manage', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
                    <form method="post" action="<?= e(url('/families/' . $familyId . '/ai/oral-sessions/' . (int) $session['id'] . '/consent')) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="grant"><button class="button button--small" type="submit">记录授权</button>
                    </form>
                    <form method="post" action="<?= e(url('/families/' . $familyId . '/ai/oral-sessions/' . (int) $session['id'] . '/consent')) ?>" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revoke"><button class="button button--ghost button--small" type="submit">撤回授权</button>
                    </form>
                <?php endif; ?>
                <?php if (($session['consent_status'] ?? '') === 'granted' && Policy::can('ai.workflow.create', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
                <form class="form" method="post" action="<?= e(url('/families/' . $familyId . '/ai/oral-sessions/' . (int) $session['id'] . '/segments')) ?>">
                    <?= csrf_field() ?>
                    <div class="field"><label>说话人</label><input name="speaker"></div>
                    <div class="field"><label>起止秒</label><input name="start_time" type="number" min="0"> <input name="end_time" type="number" min="0"></div>
                    <div class="field"><label>转写内容</label><textarea name="transcript" rows="3"></textarea></div>
                    <button class="button button--small" type="submit">保存转写片段</button>
                </form>
                <?php endif; ?>
                <?php foreach (AiWorkflowService::segments((int) $session['id']) as $segment): ?>
                    <div class="notice-card">
                        <p class="small"><?= e($segment['speaker'] ?? '') ?> · <?= e($segment['review_status']) ?></p>
                        <p><?= e(mb_substr((string) $segment['corrected_transcript'] ?: $segment['transcript'], 0, 300)) ?></p>
                        <?php if (Policy::can('ai.result.review', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
                        <form method="post" action="<?= e(url('/ai/segments/' . (int) $segment['id'] . '/review')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="status" value="confirmed">
                            <button class="button button--small" type="submit">确认片段</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($logs !== []): ?>
    <section class="section section--tight">
        <h2>生成记录</h2>
        <table class="table">
            <?php foreach ($logs as $log): ?>
                <tr><td>#<?= (int) $log['id'] ?></td><td><?= e($log['provider']) ?> / <?= e($log['model']) ?></td><td><?= e($log['prompt_version']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
    <?php endif; ?>
</div>
