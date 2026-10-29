<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Notify;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Article;
use App\Models\Media;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Report;
use App\Models\Tribute;
use App\Models\User;
use App\Services\MemorialService;

final class ReportController
{
    private const STATUSES = ['open', 'reviewing', 'resolved', 'dismissed'];
    private const HANDLE_STATUSES = ['reviewing', 'resolved', 'dismissed'];
    private const HIDEABLE = ['message', 'tribute', 'media', 'article'];

    public function index(Request $request): Response
    {
        $this->guard();

        $status = (string) $request->input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $total = Report::count($status !== '' ? $status : null);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/reports/index', [
            'title' => '举报处理',
            'noindex' => true,
            'active' => 'reports',
            'reports' => Report::paginate($status !== '' ? $status : null, $paginator->perPage, $paginator->offset),
            'status' => $status,
            'paginator' => $paginator,
            'query' => $status !== '' ? ['status' => $status] : [],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->guard();
        return $this->renderShow((int) $id);
    }

    public function resolve(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $report = $this->findReport((int) $id);
        $target = $this->loadTarget($report);

        $status = (string) $request->input('status', '');
        $resolution = trim((string) $request->input('resolution', ''));
        $hideTarget = $request->bool('hide_target');
        $suspendMemorial = $request->bool('suspend_memorial');
        $suspendReason = trim((string) $request->input('suspend_reason', ''));

        $errors = [];
        if (!in_array($status, self::HANDLE_STATUSES, true)) {
            $errors['status'] = '处理状态只能设为处理中、已处理或已驳回';
        }
        if (mb_strlen($resolution) > 500) {
            $errors['resolution'] = '处理说明不能超过 500 字';
        }
        if (in_array($status, ['resolved', 'dismissed'], true) && $resolution === '') {
            $errors['resolution'] = '处理完成或驳回时必须填写处理说明';
        }
        if ($status === 'dismissed' && ($hideTarget || $suspendMemorial)) {
            $errors['form'] = '驳回举报时不能同时隐藏内容或暂停纪念馆';
        }
        if ($hideTarget) {
            if (($report['target_type'] ?? '') === 'memorial') {
                $errors['hide_target'] = '纪念馆不能单独隐藏，请使用“暂停纪念馆”并填写理由';
            } elseif ($target === null) {
                $errors['hide_target'] = '举报目标已不存在，无法执行隐藏；仍可登记处理结论';
            } elseif (!in_array($report['target_type'], self::HIDEABLE, true)) {
                $errors['hide_target'] = '该类型目标不支持隐藏';
            }
        }

        $memorialForSuspend = null;
        if ($suspendMemorial) {
            $memorialId = (int) ($report['memorial_id'] ?? 0);
            if ($memorialId <= 0 && $target !== null && $target['type'] === 'memorial') {
                $memorialId = (int) $target['id'];
            }
            $memorialForSuspend = $memorialId > 0 ? Memorial::find($memorialId) : null;
            if ($memorialForSuspend === null) {
                $errors['suspend_memorial'] = '举报未关联有效纪念馆，无法暂停';
            } elseif (mb_strlen($suspendReason) < 5 || mb_strlen($suspendReason) > 500) {
                $errors['suspend_reason'] = '暂停理由需为 5–500 字';
            }
        }

        if ($errors !== []) {
            return $this->renderShow((int) $report['id'], $errors, [
                'status' => $status,
                'resolution' => $resolution,
                'hide_target' => $hideTarget,
                'suspend_memorial' => $suspendMemorial,
                'suspend_reason' => $suspendReason,
            ], 422);
        }

        $actions = [];
        Db::transaction(static function () use (
            $report,
            $target,
            $status,
            $resolution,
            $hideTarget,
            $suspendMemorial,
            $suspendReason,
            $memorialForSuspend,
            $admin,
            &$actions
        ): void {
            if ($hideTarget && $target !== null) {
                self::hideTarget($target, (int) $admin['id']);
                $actions[] = 'hide_target';
                Audit::log('admin.report.hide_target', (string) $report['target_type'], (int) $report['target_id'], [
                    'report_id' => (int) $report['id'],
                ]);
            }

            if ($suspendMemorial && $memorialForSuspend !== null) {
                MemorialService::suspendByAdmin($memorialForSuspend, $suspendReason, (int) $admin['id']);
                $actions[] = 'suspend_memorial';
                Audit::log('admin.report.suspend_memorial', 'memorial', (int) $memorialForSuspend['id'], [
                    'report_id' => (int) $report['id'],
                    'reason' => $suspendReason,
                ]);
            }

            Report::updateStatus(
                (int) $report['id'],
                $status,
                (int) $admin['id'],
                $resolution !== '' ? $resolution : null
            );

            Audit::log('admin.report.resolve', 'report', (int) $report['id'], [
                'status' => $status,
                'actions' => $actions,
                'resolution' => $resolution,
            ]);
        });

        // 仅向留下合法邮箱的举报人回执处理结果；正文只含编号、受理类型与结论，
        // 不复制被举报内容摘要，避免把敏感目标正文带出后台。
        $contact = trim((string) ($report['contact'] ?? ''));
        if (in_array($status, ['resolved', 'dismissed'], true)
            && $contact !== ''
            && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false
        ) {
            $targetTypeLabel = match ((string) $report['target_type']) {
                'memorial' => '纪念馆',
                'message' => '留言',
                'tribute' => '祭奠记录',
                'media' => '影像资料',
                'article' => '纪念文章',
                default => '其他内容',
            };
            $statusLabel = $status === 'resolved' ? '已处理' : '已驳回';

            Notify::send(
                $contact,
                '静思园·举报处理结果',
                "您好：\n\n"
                . '您提交的举报（编号 #' . (int) $report['id'] . "）已处理完毕。\n\n"
                . '受理类型：' . $targetTypeLabel . '（' . report_reason_label((string) $report['reason']) . "）\n"
                . '处理状态：' . $statusLabel . "\n"
                . '处理结论：' . $resolution . "\n\n"
                . "本邮件仅告知处理结果，不包含被举报内容详情。如有疑问，请联系 "
                . (string) config('app.support_email', 'support@example.com') . "。\n"
                . '——静思园'
            );
        }

        Flash::success('举报处理结果已保存，相关处置已写入操作日志。');
        return Response::redirect('/admin/reports/' . (int) $report['id']);
    }

    private function renderShow(int $id, array $errors = [], array $old = [], int $status = 200): Response
    {
        $report = $this->findReport($id);

        return View::render('admin/reports/show', [
            'title' => '举报 #' . $id,
            'noindex' => true,
            'active' => 'reports',
            'report' => $report,
            'target' => $this->loadTarget($report),
            'handler' => !empty($report['handled_by']) ? User::find((int) $report['handled_by']) : null,
            'reporter' => !empty($report['reporter_user_id']) ? User::find((int) $report['reporter_user_id']) : null,
            'errors' => $errors,
            'old' => $old,
        ], 'layout', $status);
    }

    private function findReport(int $id): array
    {
        $report = Report::find($id);
        if ($report === null) {
            throw new HttpException(404, '举报不存在');
        }
        return $report;
    }

    /**
     * @return array{type:string,type_label:string,id:int,label:string,summary:string,status:string,memorial:?array}|null
     */
    private function loadTarget(array $report): ?array
    {
        $type = (string) $report['target_type'];
        $id = (int) $report['target_id'];

        switch ($type) {
            case 'memorial':
                $memorial = Memorial::find($id);
                if ($memorial === null) {
                    return null;
                }
                return [
                    'type' => $type,
                    'type_label' => '纪念馆',
                    'id' => $id,
                    'label' => $memorial['title'],
                    'summary' => (string) ($memorial['summary'] ?: $memorial['bio']),
                    'status' => (string) $memorial['status'],
                    'memorial' => $memorial,
                ];

            case 'message':
                $message = Message::find($id);
                if ($message === null) {
                    return null;
                }
                $memorial = Memorial::find((int) $message['memorial_id']);
                if ($memorial === null) {
                    return null;
                }
                return [
                    'type' => $type,
                    'type_label' => '留言',
                    'id' => $id,
                    'label' => '留言 · ' . $message['author_name'],
                    'summary' => (string) $message['content'],
                    'status' => (string) $message['status'],
                    'memorial' => $memorial,
                ];

            case 'tribute':
                $tribute = Tribute::find($id);
                if ($tribute === null) {
                    return null;
                }
                $memorial = Memorial::find((int) $tribute['memorial_id']);
                if ($memorial === null) {
                    return null;
                }
                return [
                    'type' => $type,
                    'type_label' => '祭奠记录',
                    'id' => $id,
                    'label' => '祭奠记录 · ' . tribute_label((string) $tribute['type']),
                    'summary' => (string) ($tribute['note'] ?? ''),
                    'status' => (string) $tribute['status'],
                    'memorial' => $memorial,
                ];

            case 'media':
                $media = Media::find($id);
                if ($media === null) {
                    return null;
                }
                $memorial = Memorial::find((int) $media['memorial_id']);
                if ($memorial === null) {
                    return null;
                }
                return [
                    'type' => $type,
                    'type_label' => '影像资料',
                    'id' => $id,
                    'label' => media_kind_label((string) $media['kind']) . ' · ' . $media['original_name'],
                    'summary' => (string) ($media['caption'] ?? ''),
                    'status' => (string) $media['status'],
                    'memorial' => $memorial,
                ];

            case 'article':
                $article = Article::find($id);
                if ($article === null) {
                    return null;
                }
                $memorial = Memorial::find((int) $article['memorial_id']);
                if ($memorial === null) {
                    return null;
                }
                return [
                    'type' => $type,
                    'type_label' => '纪念文章',
                    'id' => $id,
                    'label' => '文章 · ' . $article['title'],
                    'summary' => (string) $article['content'],
                    'status' => (string) $article['status'],
                    'memorial' => $memorial,
                ];
        }

        return null;
    }

    private static function hideTarget(array $target, int $adminId): void
    {
        switch ($target['type']) {
            case 'message':
                Message::setStatus((int) $target['id'], 'hidden', $adminId);
                break;
            case 'tribute':
                Tribute::setStatus((int) $target['id'], 'hidden', $adminId);
                break;
            case 'media':
                Media::hide((int) $target['id']);
                break;
            case 'article':
                Article::setStatus((int) $target['id'], 'hidden');
                break;
        }
    }

    private function guard(): array
    {
        $admin = Auth::user();
        if ($admin === null || ($admin['role'] ?? '') !== 'admin') {
            throw new HttpException(403, '没有访问权限');
        }
        return $admin;
    }
}
