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
use App\Models\Article;
use App\Models\Media;
use App\Models\Message;

final class ModerationController
{
    public function index(Request $request): Response
    {
        $this->guard();
        return $this->renderIndex();
    }

    public function messageStatus(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $message = Message::find((int) $id);
        if ($message === null) {
            throw new HttpException(404, '留言不存在');
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, ['visible', 'hidden'], true)) {
            return $this->renderIndex([
                'form' => '留言状态只能设为展示或隐藏',
            ], ['id' => (int) $id, 'status' => $status], 422);
        }

        Message::setStatus((int) $message['id'], $status, (int) $admin['id']);
        Audit::log('admin.message.status', 'message', (int) $message['id'], [
            'status' => $status,
            'memorial_id' => (int) $message['memorial_id'],
        ]);

        Flash::success($status === 'visible' ? '留言已通过审核并展示。' : '留言已隐藏，不会在前台展示。');
        return Response::redirect('/admin/moderation');
    }

    public function articleStatus(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $article = Article::find((int) $id);
        if ($article === null) {
            throw new HttpException(404, '文章不存在');
        }

        $action = (string) $request->input('action', '');
        $note = trim((string) $request->input('review_note', ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->renderIndex([
                'form' => '请选择通过或驳回文章',
            ], ['id' => (int) $id, 'action' => $action, 'review_note' => $note], 422);
        }
        if (mb_strlen($note) > 500) {
            return $this->renderIndex([
                'form' => '审核说明不能超过 500 字',
            ], ['id' => (int) $id, 'action' => $action, 'review_note' => $note], 422);
        }

        if ($action === 'approve') {
            Article::setStatus((int) $article['id'], 'published');
            Audit::log('admin.article.approve', 'article', (int) $article['id'], [
                'memorial_id' => (int) $article['memorial_id'],
            ]);
            Flash::success('文章已通过审核并发布。');
        } else {
            Article::setStatus((int) $article['id'], 'rejected', $note !== '' ? $note : null);
            Audit::log('admin.article.reject', 'article', (int) $article['id'], [
                'memorial_id' => (int) $article['memorial_id'],
                'review_note' => $note,
            ]);
            Flash::success('文章已驳回，审核说明将保留在记录中。');
        }

        return Response::redirect('/admin/moderation');
    }

    public function mediaStatus(Request $request, string $id): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $media = Media::find((int) $id);
        if ($media === null) {
            throw new HttpException(404, '媒体文件不存在');
        }

        $action = (string) $request->input('action', '');
        if (!in_array($action, ['hide', 'show'], true)) {
            return $this->renderIndex([
                'form' => '媒体状态只能设为隐藏或恢复展示',
            ], ['id' => (int) $id, 'action' => $action], 422);
        }

        if ($action === 'hide') {
            Media::hide((int) $media['id']);
            $message = '媒体已隐藏，前台与直链均不可访问。';
            $auditAction = 'admin.media.hide';
        } else {
            Media::show((int) $media['id']);
            $message = '媒体已恢复展示。';
            $auditAction = 'admin.media.show';
        }

        Audit::log($auditAction, 'media', (int) $media['id'], [
            'memorial_id' => (int) $media['memorial_id'],
        ]);

        Flash::success($message);
        return Response::redirect('/admin/moderation');
    }

    /** 批量处理待审留言：ids[] = 留言 id，status ∈ visible|hidden。 */
    public function messageBatch(Request $request): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $status = (string) $request->raw('status', '');
        if (!in_array($status, ['visible', 'hidden'], true)) {
            return $this->renderIndex(['form' => '批量审核留言只能选择通过或隐藏'], ['batch' => 'messages'], 422);
        }

        $ids = $this->parseIds($request);
        if ($ids === null) {
            return $this->renderIndex(['form' => '请先勾选要处理的留言'], ['batch' => 'messages'], 422);
        }

        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $message = Message::find($id);
            if ($message === null || (string) $message['status'] !== 'pending') {
                $skipped++;
                continue;
            }
            Message::setStatus($id, $status, (int) $admin['id']);
            Audit::log('admin.message.status', 'message', $id, [
                'status' => $status,
                'memorial_id' => (int) $message['memorial_id'],
                'batch' => true,
            ]);
            $done++;
        }

        Flash::success(sprintf('批量处理完成：留言已%s %d 条，跳过 %d 条（不存在或已处理）。', $status === 'visible' ? '通过展示' : '隐藏', $done, $skipped));
        return Response::redirect('/admin/moderation');
    }

    /** 批量处理待审文章：ids[] = 文章 id，action ∈ approve|reject，可附统一审核说明。 */
    public function articleBatch(Request $request): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $action = (string) $request->raw('action', '');
        $note = trim((string) $request->input('review_note', ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return $this->renderIndex(['form' => '批量审核文章只能选择通过或驳回'], ['batch' => 'articles'], 422);
        }
        if (mb_strlen($note) > 500) {
            return $this->renderIndex(['form' => '审核说明不能超过 500 字'], ['batch' => 'articles', 'review_note' => $note], 422);
        }

        $ids = $this->parseIds($request);
        if ($ids === null) {
            return $this->renderIndex(['form' => '请先勾选要处理的文章'], ['batch' => 'articles'], 422);
        }

        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $article = Article::find($id);
            if ($article === null || (string) $article['status'] !== 'pending') {
                $skipped++;
                continue;
            }
            if ($action === 'approve') {
                Article::setStatus($id, 'published');
                Audit::log('admin.article.approve', 'article', $id, [
                    'memorial_id' => (int) $article['memorial_id'],
                    'batch' => true,
                ]);
            } else {
                Article::setStatus($id, 'rejected', $note !== '' ? $note : null);
                Audit::log('admin.article.reject', 'article', $id, [
                    'memorial_id' => (int) $article['memorial_id'],
                    'review_note' => $note,
                    'batch' => true,
                ]);
            }
            $done++;
        }

        $verb = $action === 'approve' ? '通过发布' : '驳回';
        Flash::success(sprintf('批量处理完成：文章已%s %d 篇，跳过 %d 篇（不存在或已处理）。', $verb, $done, $skipped));
        return Response::redirect('/admin/moderation');
    }

    /** 批量处理媒体：ids[] = 媒体 id，action ∈ hide|show。 */
    public function mediaBatch(Request $request): Response
    {
        Csrf::verify($request);
        $admin = $this->guard();

        $action = (string) $request->raw('action', '');
        if (!in_array($action, ['hide', 'show'], true)) {
            return $this->renderIndex(['form' => '批量操作媒体只能选择隐藏或恢复展示'], ['batch' => 'media'], 422);
        }

        $ids = $this->parseIds($request);
        if ($ids === null) {
            return $this->renderIndex(['form' => '请先勾选要处理的媒体'], ['batch' => 'media'], 422);
        }

        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $media = Media::find($id);
            if ($media === null) {
                $skipped++;
                continue;
            }
            if ($action === 'show' && (string) $media['status'] !== 'hidden') {
                $skipped++;
                continue;
            }
            if ($action === 'hide') {
                Media::hide($id);
                Audit::log('admin.media.hide', 'media', $id, [
                    'memorial_id' => (int) $media['memorial_id'],
                    'batch' => true,
                ]);
            } else {
                Media::show($id);
                Audit::log('admin.media.show', 'media', $id, [
                    'memorial_id' => (int) $media['memorial_id'],
                    'batch' => true,
                ]);
            }
            $done++;
        }

        $verb = $action === 'hide' ? '已隐藏' : '已恢复展示';
        Flash::success(sprintf('批量处理完成：媒体%s %d 个，跳过 %d 个（不存在或状态不符）。', $verb, $done, $skipped));
        return Response::redirect('/admin/moderation');
    }

    /** 解析并去重的勾选 id 列表；上Arthur 100 条，空返回 null。 */
    private function parseIds(Request $request): ?array
    {
        $raw = $request->raw('ids', []);
        if (!is_array($raw)) {
            return null;
        }
        $ids = [];
        foreach ($raw as $v) {
            if (is_numeric($v)) {
                $ids[(int) $v] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === [] || count($ids) > 100) {
            return null;
        }
        return array_values($ids);
    }

    private function renderIndex(array $errors = [], array $old = [], int $status = 200): Response
    {
        $hiddenMedia = Db::fetchAll(
            "SELECT md.*, m.slug, d.full_name
             FROM media md
             JOIN memorials m ON m.id = md.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE md.status = 'hidden'
             ORDER BY md.created_at DESC, md.id DESC LIMIT 50"
        );

        $recentMedia = Db::fetchAll(
            "SELECT md.*, m.slug, d.full_name
             FROM media md
             JOIN memorials m ON m.id = md.memorial_id
             JOIN deceased d ON d.id = m.deceased_id
             WHERE md.status = 'active'
             ORDER BY md.created_at DESC, md.id DESC LIMIT 20"
        );

        return View::render('admin/moderation', [
            'title' => '内容审核',
            'noindex' => true,
            'active' => 'moderation',
            'pendingMessages' => Message::pendingAll(50),
            'pendingArticles' => Article::pending(50, 0),
            'hiddenMedia' => $hiddenMedia,
            'recentMedia' => $recentMedia,
            'errors' => $errors,
            'old' => $old,
        ], 'layout', $status);
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
