<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
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
        $order = AiOrder::find((int) $id);
        if ($order === null) {
            throw new HttpException(404, '订单不存在');
        }
        if ((string) $order['status'] !== 'pending') {
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
        if ((bool) config('ai.sync', true)) {
            try {
                AiStudioService::process($jobId);
            } catch (\Throwable $e) {
                // 任务保留 failed 状态，可在「AI 任务」页重试，不影响订单已确认的事实。
            }
        }

        AiOrder::update((int) $order['id'], [
            'status' => 'paid',
            'task_id' => $jobId,
            'payment_ref' => $paymentRef,
            'paid_at' => date('Y-m-d H:i:s'),
            'reviewed_by' => Auth::id(),
        ]);
        Audit::log('admin.ai_order_confirmed', 'ai_order', (int) $order['id'], [
            'service' => (string) $order['service_slug'],
            'price_cents' => (int) $order['price_cents'],
            'job_id' => $jobId,
            'memorial_id' => (int) $order['memorial_id'],
        ]);
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

        AiOrder::update((int) $order['id'], [
            'status' => 'rejected',
            'note' => $note,
            'reviewed_by' => Auth::id(),
        ]);
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
