<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AiJob;
use App\Models\AiOrder;
use App\Models\AiService;
use App\Services\AiStudioService;

/**
 * AI 订单（订单审核制）：馆主提交任务先落 pending 订单，
 * 平台人工确认收款后由本控制器生成并执行真实 AI 任务。
 */
final class AiOrderController
{
    public function index(Request $request): Response
    {
        $this->guard();
        $status = (string) $request->input('status', '');
        if (!in_array($status, ['', 'pending', 'paid', 'rejected', 'cancelled'], true)) {
            $status = '';
        }

        return View::render('admin/ai-orders', [
            'title' => 'AI 订单',
            'noindex' => true,
            'active' => 'ai-orders',
            'orders' => AiOrder::recent(50, $status !== '' ? $status : null),
            'status' => $status,
        ]);
    }

    public function confirm(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $this->guard();

        // 确认收款事务：并发确认时只有一个请求能通过条件更新；
        // 外部 AI 调用移到事务外，避免长事务占用订单行锁。
        try {
            $result = Db::transaction(static function () use ($request, $id): Response|int {
                $order = AiOrder::find((int) $id);
                if ($order === null) {
                    throw new HttpException(404, '订单不存在');
                }
                $locked = Db::fetch('SELECT status FROM ai_orders WHERE id = ? FOR UPDATE', [$id]);
                if ($locked === null || (string) $locked['status'] !== 'pending') {
                    Flash::error('只有待确认订单可以确认收款。');
                    return Response::redirect('/admin/ai-orders');
                }

                $service = AiService::findBySlug((string) $order['service_slug']);
                if ($service === null || !(bool) $service['enabled']) {
                    Flash::error('该 AI 服务已下架或停用，请先在「AI 服务与价格」恢复开放，或驳回订单。');
                    return Response::redirect('/admin/ai-orders');
                }

                $input = json_decode((string) $order['input_json'], true);
                if (!is_array($input) || $input === []) {
                    Flash::error('订单内容缺失，无法生成任务，请驳回并通知用户重新提交。');
                    return Response::redirect('/admin/ai-orders');
                }

                $paymentRef = mb_substr(trim((string) $request->input('payment_ref', '')), 0, 190);
                if (mb_strlen($paymentRef) > 190) {
                    $paymentRef = mb_substr($paymentRef, 0, 190);
                }

                // 确认收款 = 记账后正式生成任务；付费订单不占每日免费额度。
                $jobId = AiJob::create(
                    (int) $order['memorial_id'],
                    (int) $order['user_id'],
                    (string) $order['service_slug'],
                    $input,
                    (int) $order['price_cents']
                );

                // 条件更新兜底：仅当订单仍是 pending 才落账，避免与 reject/另一确认请求竞态。
                $updated = Db::update('ai_orders', [
                    'status' => 'paid',
                    'task_id' => $jobId,
                    'payment_ref' => $paymentRef,
                    'paid_at' => date('Y-m-d H:i:s'),
                    'reviewed_by' => ($actor = Auth::user()) !== null ? (int) $actor['id'] : null,
                ], 'id = ? AND status = \'pending\'', [(int) $order['id']]);
                if ($updated === 0) {
                    // 回滚事务，撤销刚创建的任务，绝不产生第二笔任务。
                    throw new AiOrderConfirmConflict();
                }

                Audit::log('admin.ai_order_confirmed', 'ai_order', (int) $order['id'], [
                    'service' => (string) $order['service_slug'],
                    'price_cents' => (int) $order['price_cents'],
                    'job_id' => $jobId,
                    'memorial_id' => (int) $order['memorial_id'],
                ]);
                return $jobId;
            });
        } catch (AiOrderConfirmConflict $e) {
            Flash::error('只有待确认订单可以确认收款。');
            return Response::redirect('/admin/ai-orders');
        }

        if ($result instanceof Response) {
            return $result;
        }
        $jobId = $result;

        // 外部调用与事务解耦：订单已 paid 且任务已落库，处理失败也不回滚订单。
        if ((bool) config('ai.sync', true)) {
            try {
                AiStudioService::process($jobId);
            } catch (\Throwable $e) {
                // 任务保留 failed 状态，可在「AI 任务」页重试；不记录异常细节，避免泄露敏感信息。
            }
        }
        Flash::success('订单已确认收款并生成 AI 任务（#' . $jobId . '）。');
        return Response::redirect('/admin/ai-orders');
    }

    public function reject(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $this->guard();
        $order = AiOrder::find((int) $id);
        if ($order === null) {
            throw new HttpException(404, '订单不存在');
        }
        if ((string) $order['status'] !== 'pending') {
            Flash::error('只有待确认订单可以驳回。');
            return Response::redirect('/admin/ai-orders');
        }

        $note = trim((string) $request->input('note', ''));
        if ($note === '' || mb_strlen($note) > 500) {
            Flash::error('请填写不超过 500 字的驳回说明，便于用户理解原因。');
            return Response::redirect('/admin/ai-orders');
        }

        // 条件更新兜底：仅当订单仍是 pending 才驳回，避免与 confirm 竞态。
        $updated = Db::update('ai_orders', [
            'status' => 'rejected',
            'note' => $note,
            'reviewed_by' => ($actor = Auth::user()) !== null ? (int) $actor['id'] : null,
        ], 'id = ? AND status = \'pending\'', [(int) $order['id']]);
        if ($updated === 0) {
            Flash::error('只有待确认订单可以驳回。');
            return Response::redirect('/admin/ai-orders');
        }

        Audit::log('admin.ai_order_rejected', 'ai_order', (int) $order['id'], [
            'service' => (string) $order['service_slug'],
            'memorial_id' => (int) $order['memorial_id'],
        ]);
        Flash::success('订单已驳回，用户可在馆内看到处理结果。');
        return Response::redirect('/admin/ai-orders');
    }

    private function guard(): void
    {
        if (!Auth::isAdmin()) {
            throw new HttpException(403, '无权访问');
        }
    }
}

/** 条件更新未命中（订单已被并发请求处理）：回滚事务并转为冲突提示。 */
final class AiOrderConfirmConflict extends \RuntimeException
{
}
