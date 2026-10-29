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
