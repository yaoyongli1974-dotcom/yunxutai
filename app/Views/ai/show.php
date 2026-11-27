<?php
use App\Core\Auth;
use App\Core\Policy;
use App\Services\AiWorkflowService;
/** @var array<string,mixed> $family */
/** @var array<string,mixed> $workflow */
/** @var list<array<string,mixed>> $steps */
$familyId = (int) $family['id'];
$output = json_decode((string) ($workflow['output_json'] ?? ''), true) ?: [];
?>
<div class="shell" style="padding:2.5rem 0;">
    <div class="section-heading section-heading--row">
        <div><p class="eyebrow">AI 任务详情</p><h1>#<?= (int) $workflow['id'] ?> · <?= e($workflow['workflow_type']) ?></h1></div>
        <a class="button button--ghost button--small" href="<?= e(url('/families/' . $familyId . '/ai')) ?>">返回工坊</a>
    </div>
    <div class="notice-card"><p>状态：<?= e($workflow['status']) ?> · 当前步骤：<?= e($workflow['current_step'] ?? '—') ?> · 重试：<?= (int) $workflow['retry_count'] ?> / <?= (int) $workflow['max_retries'] ?><?php if (!empty($workflow['error_message'])): ?> · 错误：<?= e($workflow['error_message']) ?><?php endif; ?></p></div>

    <?php if (Policy::can('ai.workflow.create', ['family_id' => $familyId, 'family' => $family], Auth::user()) && in_array($workflow['status'], ['queued', 'failed'], true)): ?>
        <form method="post" action="<?= e(url('/families/' . $familyId . '/ai/workflows/' . (int) $workflow['id'] . '/run')) ?>">
            <?= csrf_field() ?><button class="button button--small" type="submit">执行任务</button>
        </form>
    <?php endif; ?>
    <?php if (in_array($workflow['status'], ['queued', 'failed', 'processing'], true) && Policy::can('ai.workflow.create', ['family_id' => $familyId, 'family' => $family], Auth::user())): ?>
        <form method="post" action="<?= e(url('/families/' . $familyId . '/ai/workflows/' . (int) $workflow['id'] . '/cancel')) ?>">
            <?= csrf_field() ?><button class="button button--ghost button--small" type="submit">取消任务</button>
        </form>
    <?php endif; ?>

    <section class="section section--tight">
        <h2>执行步骤</h2>
        <table class="table">
            <?php foreach ($steps as $step): ?>
                <tr><td><?= (int) $step['step_no'] ?></td><td><?= e($step['name']) ?></td><td><?= e($step['status']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </section>

    <?php if ($output !== []): ?>
    <section class="section section--tight">
        <h2>草稿输出（需人工审核）</h2>
        <div class="card"><p><?= nl2br(e((string) ($output['content'] ?? ''))) ?></p></div>
        <?php if (!empty($output['facts'])): ?>
            <h3>候选事实</h3>
            <?php foreach ($output['facts'] as $fact): ?>
                <div class="notice-card"><p class="small"><?= e($fact['fact_type'] ?? '') ?> · <?= e($fact['source_type'] ?? '') ?> · 待审核</p><p><?= e($fact['fact_content'] ?? '') ?></p></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
