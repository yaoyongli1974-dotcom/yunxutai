<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Core\View;
use App\Models\Memorial;
use App\Models\Verification;

final class VerificationController
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    public function index(Request $request): Response
    {
        $this->guard();

        $status = (string) $request->input('status', 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }

        $total = Verification::count($status);
        $paginator = Paginator::fromRequest($request, $total, 20);

        return View::render('admin/verifications/index', [
            'title' => '身份核验',
            'noindex' => true,
            'active' => 'verifications',
            'status' => $status,
            'items' => Verification::paginate($status, $paginator->perPage, $paginator->offset),
            'paginator' => $paginator,
            'counts' => [
                'pending' => Verification::count('pending'),
                'approved' => Verification::count('approved'),
                'rejected' => Verification::count('rejected'),
            ],
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $this->guard();
        $verification = $this->load((int) $id);

        return View::render('admin/verifications/show', [
            'title' => '核验材料 #' . (int) $verification['id'],
            'noindex' => true,
            'active' => 'verifications',
            'verification' => $verification,
            'memorial' => Memorial::find((int) $verification['memorial_id']),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function document(Request $request, string $id): Response
    {
        $this->guard();
        $verification = $this->load((int) $id);
        $path = Storage::verificationAbsolute($verification['document_path'] ?? null);
        if ($path === null) {
            throw new HttpException(404, '材料文件不存在');
        }

        $mime = (string) ($verification['document_mime'] ?: 'application/octet-stream');
        $name = (string) ($verification['document_name'] ?: ('verification-' . $verification['id']));
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'verification';

        Audit::log('admin.verification_document', 'memorial', (int) $verification['memorial_id'], [
            'verification_id' => (int) $verification['id'],
        ]);

        return Response::file($path, $mime, 200, [
            'Content-Disposition' => 'inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function review(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();
        $verification = $this->load((int) $id);
        $memorialId = (int) $verification['memorial_id'];

        $action = (string) $request->input('action', '');
        $note = trim((string) $request->input('review_note', ''));
        $errors = [];

        if (!in_array($action, ['approve', 'reject'], true)) {
            $errors['action'] = '请选择通过或驳回';
        }
        if ($action === 'reject') {
            $length = mb_strlen($note);
            if ($length < 5) {
                $errors['review_note'] = '驳回时请填写至少 5 个字的理由，便于馆主补充材料';
            } elseif ($length > 500) {
                $errors['review_note'] = '审核说明不能超过 500 字';
            }
        }

        if ($verification['status'] !== 'pending') {
            Flash::error('该核验申请已处理，不能重复审核。');
            return Response::redirect('/admin/verifications/' . (int) $verification['id']);
        }

        if ($errors !== []) {
            return View::render('admin/verifications/show', [
                'title' => '核验材料 #' . (int) $verification['id'],
                'noindex' => true,
                'active' => 'verifications',
                'verification' => $verification,
                'memorial' => Memorial::find($memorialId),
                'errors' => $errors,
                'old' => $request->post,
            ], 'layout', 422);
        }

        Db::transaction(static function () use ($verification, $action, $note, $admin, $memorialId): void {
            if ($action === 'approve') {
                Verification::setStatus((int) $verification['id'], 'approved', (int) $admin['id'], $note ?: null);
                Memorial::update($memorialId, [
                    'verification_status' => 'verified',
                    'verification_method' => (string) $verification['method'],
                    'verified_at' => date('Y-m-d H:i:s'),
                    'verified_by' => (int) $admin['id'],
                ]);
                Audit::log('admin.verification_approved', 'memorial', $memorialId, [
                    'verification_id' => (int) $verification['id'],
                    'method' => (string) $verification['method'],
                ]);
            } else {
                Verification::setStatus((int) $verification['id'], 'rejected', (int) $admin['id'], $note);
                Memorial::update($memorialId, [
                    'verification_status' => 'rejected',
                    'verified_at' => null,
                    'verified_by' => null,
                    // 驳回后立即回退私密，防止未通过核验的纪念馆继续公开。
                    'visibility' => 'private',
                    'allow_search_index' => 0,
                ]);
                Audit::log('admin.verification_rejected', 'memorial', $memorialId, [
                    'verification_id' => (int) $verification['id'],
                    'note' => $note,
                ]);
            }
        });

        Flash::success($action === 'approve'
            ? '核验已通过。馆主现在可以在“访问与留言”中选择公开或不公开收录。'
            : '已驳回核验申请，馆主可补充材料后重新提交。');

        return Response::redirect('/admin/verifications/' . (int) $verification['id']);
    }

    private function load(int $id): array
    {
        $verification = Verification::find($id);
        if ($verification === null) {
            throw new HttpException(404, '核验申请不存在');
        }
        return $verification;
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
