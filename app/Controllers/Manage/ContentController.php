<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Core\Validator;
use App\Core\View;
use App\Models\Article;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Memorial;

final class ContentController
{
    public function life(Request $request, string $id): Response
    {
        $memorial = $this->loadEditable((int) $id);

        return $this->renderLife($memorial, [], [], null);
    }

    public function lifeStore(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        [$errors, $data] = $this->validateLife($request);

        if ($errors !== []) {
            return $this->renderLife($memorial, $errors, $data, null, 422);
        }

        $eventId = LifeEvent::create((int) $memorial['id'], $data, (int) Auth::user()['id']);
        Audit::log('life.create', 'life_event', $eventId, ['memorial_id' => (int) $memorial['id']]);

        Flash::success('生平事件已添加。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/life');
    }

    public function lifeUpdate(Request $request, string $id, string $eventId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $event = $this->loadLifeEvent((int) $eventId, (int) $memorial['id']);
        [$errors, $data] = $this->validateLife($request);

        if ($errors !== []) {
            return $this->renderLife($memorial, $errors, $data, (int) $event['id'], 422);
        }

        LifeEvent::update((int) $event['id'], $data);
        Audit::log('life.update', 'life_event', (int) $event['id'], ['memorial_id' => (int) $memorial['id']]);

        Flash::success('生平事件已更新。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/life');
    }

    public function lifeDelete(Request $request, string $id, string $eventId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $event = $this->loadLifeEvent((int) $eventId, (int) $memorial['id']);

        LifeEvent::delete((int) $event['id']);
        Audit::log('life.delete', 'life_event', (int) $event['id'], [
            'memorial_id' => (int) $memorial['id'],
            'title' => $event['title'],
        ]);

        Flash::success('生平事件已删除。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/life');
    }

    public function articles(Request $request, string $id): Response
    {
        $memorial = $this->loadEditable((int) $id);

        return $this->renderArticles($memorial, [], []);
    }

    public function articleStore(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        [$errors, $data, $action] = $this->validateArticle($request);
        $old = array_merge($data, ['action' => $action]);

        if ($errors !== []) {
            return $this->renderArticles($memorial, $errors, $old, 422);
        }

        $status = $action === 'publish' ? $this->publishStatus() : 'draft';
        $data['status'] = $status;

        $articleId = Article::create((int) $memorial['id'], $data, (int) Auth::user()['id']);
        Audit::log('article.create', 'article', $articleId, [
            'memorial_id' => (int) $memorial['id'],
            'status' => $status,
        ]);

        Flash::success($this->articleFlash($status, false));
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/articles');
    }

    public function articleEdit(Request $request, string $id, string $articleId): Response
    {
        $memorial = $this->loadEditable((int) $id);
        $article = $this->loadArticle((int) $articleId, (int) $memorial['id']);

        return View::render('manage/article-edit', [
            'title' => '编辑文章 · ' . $memorial['title'],
            'memorial' => $memorial,
            'article' => $article,
            'errors' => [],
            'old' => $article,
            'reviewRequired' => $this->reviewRequired(),
            'noindex' => true,
        ]);
    }

    public function articleUpdate(Request $request, string $id, string $articleId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $article = $this->loadArticle((int) $articleId, (int) $memorial['id']);
        [$errors, $data, $action] = $this->validateArticle($request);
        $old = array_merge($data, ['action' => $action]);

        if ($errors !== []) {
            return View::render('manage/article-edit', [
                'title' => '编辑文章 · ' . $memorial['title'],
                'memorial' => $memorial,
                'article' => $article,
                'errors' => $errors,
                'old' => $old,
                'reviewRequired' => $this->reviewRequired(),
                'noindex' => true,
            ], 'layout', 422);
        }

        $status = $action === 'publish' ? $this->publishStatus() : 'draft';
        $data['status'] = $status;

        Article::update((int) $article['id'], $data);

        // Article::update 保留了旧的 review_note；重新提交或保存草稿后，旧审核说明不应继续显示。
        Db::update('articles', ['review_note' => null], 'id = ?', [(int) $article['id']]);

        Audit::log('article.update', 'article', (int) $article['id'], [
            'memorial_id' => (int) $memorial['id'],
            'from' => $article['status'],
            'to' => $status,
        ]);

        Flash::success($this->articleFlash($status, true));
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/articles');
    }

    public function articleDelete(Request $request, string $id, string $articleId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $article = $this->loadArticle((int) $articleId, (int) $memorial['id']);

        Article::delete((int) $article['id']);
        Audit::log('article.delete', 'article', (int) $article['id'], [
            'memorial_id' => (int) $memorial['id'],
            'title' => $article['title'],
        ]);

        Flash::success('文章已删除。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/articles');
    }

    public function media(Request $request, string $id): Response
    {
        $memorial = $this->loadEditable((int) $id);

        return $this->renderMedia($memorial, [], []);
    }

    public function mediaStore(Request $request, string $id): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);

        $kind = (string) $request->input('kind', 'photo');
        $visibility = (string) $request->input('visibility', 'visitors');
        $caption = trim((string) $request->input('caption', ''));
        $file = $request->file('file');

        $errors = [];
        if (!in_array($kind, ['photo', 'video', 'audio', 'document'], true)) {
            $errors['kind'] = '请选择文件分类';
        }
        if (!in_array($visibility, ['visitors', 'members'], true)) {
            $errors['visibility'] = '请选择可见范围';
        }
        if (mb_strlen($caption) > 255) {
            $errors['caption'] = '说明文字不能超过 255 个字符';
        }
        if ($file === null) {
            $errors['file'] = '请选择要上传的文件';
        }

        if ($errors !== []) {
            return $this->renderMedia($memorial, $errors, [
                'kind' => $kind,
                'visibility' => $visibility,
                'caption' => $caption,
            ], 422);
        }

        try {
            $stored = Storage::storeUpload($file, $kind);
        } catch (HttpException $e) {
            Flash::error('上传未完成：' . $e->getMessage());
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
        } catch (\Throwable $e) {
            error_log('media upload failed: ' . $e->getMessage());
            Flash::error('上传未完成，请稍后重试。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
        }

        try {
            $mediaId = Media::create(
                (int) $memorial['id'],
                (int) Auth::user()['id'],
                $stored,
                $kind,
                $visibility,
                $caption
            );
        } catch (\Throwable $e) {
            Storage::delete($stored['disk_path']);
            throw $e;
        }

        Audit::log('media.upload', 'media', $mediaId, [
            'memorial_id' => (int) $memorial['id'],
            'kind' => $kind,
            'visibility' => $visibility,
        ]);

        Flash::success('文件已上传。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    public function mediaHide(Request $request, string $id, string $mediaId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $media = $this->loadMedia((int) $mediaId, (int) $memorial['id']);

        Media::hide((int) $media['id']);
        Audit::log('media.hide', 'media', (int) $media['id'], ['memorial_id' => (int) $memorial['id']]);

        Flash::success('文件已隐藏，访客不再看到。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    public function mediaShow(Request $request, string $id, string $mediaId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $media = $this->loadMedia((int) $mediaId, (int) $memorial['id']);

        Media::show((int) $media['id']);
        Audit::log('media.show', 'media', (int) $media['id'], ['memorial_id' => (int) $memorial['id']]);

        Flash::success('文件已恢复展示。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    public function mediaVisibility(Request $request, string $id, string $mediaId): Response
    {
        Csrf::verify($request);
        $memorial = $this->loadEditable((int) $id);
        $media = $this->loadMedia((int) $mediaId, (int) $memorial['id']);
        $visibility = (string) $request->input('visibility', '');
        if (!in_array($visibility, ['visitors', 'members'], true)) {
            Flash::error('可见范围不合法。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
        }

        Media::setVisibility((int) $media['id'], $visibility);
        Audit::log('media.visibility', 'media', (int) $media['id'], [
            'memorial_id' => (int) $memorial['id'],
            'visibility' => $visibility,
        ]);
        Flash::success($visibility === 'visitors' ? '文件已对访客公开。' : '文件已改为仅成员可见。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    public function mediaDelete(Request $request, string $id, string $mediaId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $media = $this->loadMedia((int) $mediaId, (int) $memorial['id']);

        if ((int) ($memorial['cover_media_id'] ?? 0) === (int) $media['id']) {
            Memorial::updateDeceased((int) $memorial['deceased_id'], ['cover_media_id' => null]);
        }

        Media::delete((int) $media['id']);
        Audit::log('media.delete', 'media', (int) $media['id'], [
            'memorial_id' => (int) $memorial['id'],
            'kind' => $media['kind'],
        ]);

        Flash::success('文件已删除。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    public function mediaCover(Request $request, string $id, string $mediaId): Response
    {
        Csrf::verify($request);

        $memorial = $this->loadEditable((int) $id);
        $media = $this->loadMedia((int) $mediaId, (int) $memorial['id']);

        if ($media['kind'] !== 'photo' || $media['status'] !== 'active' || $media['visibility'] !== 'visitors') {
            Flash::error('只有对访客展示的照片可以设为封面。');
            return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
        }

        Media::setCover((int) $memorial['id'], (int) $media['id']);
        Audit::log('media.set_cover', 'media', (int) $media['id'], ['memorial_id' => (int) $memorial['id']]);

        Flash::success('封面照片已更新。');
        return Response::redirect('/manage/memorials/' . (int) $memorial['id'] . '/media');
    }

    private function renderLife(
        array $memorial,
        array $errors,
        array $old,
        ?int $editEventId,
        int $status = 200
    ): Response {
        return View::render('manage/life', [
            'title' => '生平 · ' . $memorial['title'],
            'memorial' => $memorial,
            'events' => LifeEvent::forMemorial((int) $memorial['id']),
            'errors' => $errors,
            'old' => $old,
            'editEventId' => $editEventId,
            'noindex' => true,
        ], 'layout', $status);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function validateLife(Request $request): array
    {
        $data = [
            'event_date' => trim((string) $request->input('event_date', '')),
            'year_label' => trim((string) $request->input('year_label', '')),
            'title' => trim((string) $request->input('title', '')),
            'description' => trim((string) $request->input('description', '')),
            'location' => trim((string) $request->input('location', '')),
            'sort_order' => trim((string) $request->input('sort_order', '0')),
        ];

        $validator = Validator::make($data, [
            'event_date' => 'date',
            'year_label' => 'max:40',
            'title' => 'required|max:190',
            'description' => 'max:5000',
            'location' => 'max:190',
            'sort_order' => 'integer',
        ], [
            'event_date' => '日期',
            'year_label' => '年份说明',
            'title' => '事件标题',
            'description' => '事件叙述',
            'location' => '地点',
            'sort_order' => '排序',
        ]);

        return [$validator->errors(), $data];
    }

    private function renderArticles(array $memorial, array $errors, array $old, int $status = 200): Response
    {
        return View::render('manage/articles', [
            'title' => '纪念文章 · ' . $memorial['title'],
            'memorial' => $memorial,
            'articles' => Article::forMemorial((int) $memorial['id']),
            'errors' => $errors,
            'old' => $old,
            'reviewRequired' => $this->reviewRequired(),
            'noindex' => true,
        ], 'layout', $status);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>, 2: string}
     */
    private function validateArticle(Request $request): array
    {
        $action = (string) $request->input('action', 'draft');
        if (!in_array($action, ['draft', 'publish'], true)) {
            $action = 'draft';
        }

        $data = [
            'title' => trim((string) $request->input('title', '')),
            'author_name' => trim((string) $request->input('author_name', '')),
            'content' => trim((string) $request->input('content', '')),
        ];

        $validator = Validator::make($data, [
            'title' => 'required|max:190',
            'author_name' => 'max:80',
            'content' => 'required|min:20|max:50000',
        ], [
            'title' => '文章标题',
            'author_name' => '作者署名',
            'content' => '正文',
        ]);

        return [$validator->errors(), $data, $action];
    }

    private function renderMedia(array $memorial, array $errors, array $old, int $status = 200): Response
    {
        return View::render('manage/media', [
            'title' => '影像与文件 · ' . $memorial['title'],
            'memorial' => $memorial,
            'media' => Media::forMemorial((int) $memorial['id']),
            'errors' => $errors,
            'old' => $old,
            'maxMb' => round((int) config('uploads.max_bytes', 0) / 1048576, 1),
            'videoMb' => round((int) config('uploads.video_max_bytes', 0) / 1048576, 1),
            'noindex' => true,
        ], 'layout', $status);
    }

    private function reviewRequired(): bool
    {
        return (bool) config('moderation.article_review', false) && !Auth::isAdmin();
    }

    private function publishStatus(): string
    {
        return $this->reviewRequired() ? 'pending' : 'published';
    }

    private function articleFlash(string $status, bool $updated): string
    {
        return match ($status) {
            'draft' => '草稿已保存。草稿不会出现在公开页面。',
            'pending' => '文章已提交审核。审核通过后会出现在纪念馆的公开页面。',
            'published' => $updated ? '文章已更新并发布。' : '文章已发布。',
            default => '文章已保存。',
        };
    }

    private function loadEditable(int $id): array
    {
        $memorial = Memorial::find($id);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        if (!Policy::canEdit($memorial, Auth::user())) {
            throw new HttpException(403, '没有编辑该纪念馆的权限');
        }
        return $memorial;
    }

    private function loadLifeEvent(int $eventId, int $memorialId): array
    {
        $event = LifeEvent::find($eventId);
        if ($event === null || (int) $event['memorial_id'] !== $memorialId) {
            throw new HttpException(404, '生平事件不存在');
        }
        return $event;
    }

    private function loadArticle(int $articleId, int $memorialId): array
    {
        $article = Article::find($articleId);
        if ($article === null || (int) $article['memorial_id'] !== $memorialId) {
            throw new HttpException(404, '文章不存在');
        }
        return $article;
    }

    private function loadMedia(int $mediaId, int $memorialId): array
    {
        $media = Media::find($mediaId);
        if ($media === null || (int) $media['memorial_id'] !== $memorialId) {
            throw new HttpException(404, '文件不存在');
        }
        return $media;
    }
}
