<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Policy;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Article;
use App\Models\Claim;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Memorial;
use App\Models\Message;
use App\Models\Tribute;

final class MemorialController
{
    public function show(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $role = Policy::role($memorial, $user);
        $isMember = $role !== null;
        $counts = Memorial::counts((int) $memorial['id']);
        $tributeCounts = Tribute::countsByType((int) $memorial['id']);

        $cover = null;
        if (!empty($memorial['cover_media_id'])) {
            $candidate = Media::find((int) $memorial['cover_media_id']);
            if ($candidate
                && (int) $candidate['memorial_id'] === (int) $memorial['id']
                && $candidate['status'] === 'active'
                && ($candidate['visibility'] === 'visitors' || $isMember)) {
                $cover = $candidate;
            }
        }
        if ($cover === null) {
            $photos = Media::photos((int) $memorial['id'], 1, $isMember);
            $cover = $photos[0] ?? null;
        }

        return View::render('memorial/show', [
            'title' => $memorial['title'],
            'memorial' => $memorial,
            'user' => $user,
            'role' => $role,
            'isMember' => $isMember,
            'canEdit' => Policy::canEdit($memorial, $user),
            'canManage' => Policy::canManage($memorial, $user),
            'canPost' => Policy::canPost($memorial, $user),
            'isIndexable' => Policy::isIndexable($memorial),
            'noindex' => !Policy::isIndexable($memorial),
            'metaDescription' => excerpt_text($memorial['summary'] ?: $memorial['bio'], 150),
            'canonical' => $this->baseUrl() . '/m/' . $memorial['slug'],
            'cover' => $cover,
            'counts' => $counts,
            'tributeCounts' => $tributeCounts,
            'tributeTotal' => array_sum($tributeCounts),
            'tributes' => Tribute::recentVisible((int) $memorial['id'], 8),
            'messages' => Message::recentVisible((int) $memorial['id'], 4),
            'messageTotal' => Message::visibleCount((int) $memorial['id']),
            'photos' => Media::photos((int) $memorial['id'], 8, $isMember),
            'lifeEvents' => LifeEvent::forMemorial((int) $memorial['id']),
            'articles' => Article::publishedFor((int) $memorial['id']),
            'allowGuestbook' => (int) $memorial['allow_guestbook'] === 1,
        ]);
    }

    public function gallery(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $isMember = Policy::role($memorial, $user) !== null;
        $photos = array_values(array_filter(
            Media::visible((int) $memorial['id'], $isMember),
            static fn (array $media): bool => $media['kind'] === 'photo'
        ));

        return View::render('memorial/gallery', [
            'title' => '影像 · ' . $memorial['title'],
            'memorial' => $memorial,
            'photos' => $photos,
            'isMember' => $isMember,
            'noindex' => !Policy::isIndexable($memorial),
        ]);
    }

    public function life(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $isMember = Policy::role($memorial, $user) !== null;

        return View::render('memorial/life', [
            'title' => '生平 · ' . $memorial['title'],
            'memorial' => $memorial,
            'events' => LifeEvent::forMemorial((int) $memorial['id']),
            'isMember' => $isMember,
            'noindex' => !Policy::isIndexable($memorial),
        ]);
    }

    public function articles(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $isMember = Policy::role($memorial, $user) !== null;

        return View::render('memorial/articles', [
            'title' => '纪念文章 · ' . $memorial['title'],
            'memorial' => $memorial,
            'articles' => Article::publishedFor((int) $memorial['id']),
            'isMember' => $isMember,
            'noindex' => !Policy::isIndexable($memorial),
        ]);
    }

    public function article(Request $request, string $slug, string $id): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $article = Article::find((int) $id);
        if (!$article || (int) $article['memorial_id'] !== (int) $memorial['id']) {
            throw new HttpException(404, '文章不存在');
        }
        if ($article['status'] !== 'published' && !Policy::canEdit($memorial, $user)) {
            throw new HttpException(404, '文章不存在');
        }

        return View::render('memorial/article', [
            'title' => $article['title'] . ' · ' . $memorial['title'],
            'memorial' => $memorial,
            'article' => $article,
            'isMember' => Policy::role($memorial, $user) !== null,
            'noindex' => !Policy::isIndexable($memorial) || $article['status'] !== 'published',
        ]);
    }

    public function tributes(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $paginator = Paginator::fromRequest($request, Tribute::visibleCount((int) $memorial['id']), 20);

        return View::render('memorial/tributes', [
            'title' => '祭奠记录 · ' . $memorial['title'],
            'memorial' => $memorial,
            'tributes' => Tribute::visiblePaginate((int) $memorial['id'], $paginator->perPage, $paginator->offset),
            'counts' => Tribute::countsByType((int) $memorial['id']),
            'paginator' => $paginator,
            'isMember' => Policy::role($memorial, $user) !== null,
            'noindex' => !Policy::isIndexable($memorial),
        ]);
    }

    public function messages(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $paginator = Paginator::fromRequest($request, Message::visibleCount((int) $memorial['id']), 15);

        return View::render('memorial/messages', [
            'title' => '留言 · ' . $memorial['title'],
            'memorial' => $memorial,
            'messages' => Message::visiblePaginate((int) $memorial['id'], $paginator->perPage, $paginator->offset),
            'paginator' => $paginator,
            'canPost' => Policy::canPost($memorial, $user) && (int) $memorial['allow_guestbook'] === 1,
            'isMember' => Policy::role($memorial, $user) !== null,
            'noindex' => !Policy::isIndexable($memorial),
        ]);
    }

    public function claimForm(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        if (Policy::role($memorial, $user) !== null) {
            Flash::info('你已经是该纪念馆的成员。');
            return Response::redirect('/m/' . $memorial['slug']);
        }

        return View::render('memorial/claim', [
            'title' => '申请管理 · ' . $memorial['title'],
            'memorial' => $memorial,
            'errors' => [],
            'old' => [],
            'noindex' => true,
        ]);
    }

    public function claimStore(Request $request, string $slug): Response
    {
        Csrf::verify($request);
        [$memorial, $user] = $this->loadViewable($slug);

        if (Policy::role($memorial, $user) !== null) {
            return Response::redirect('/m/' . $memorial['slug']);
        }

        $data = [
            'relationship' => trim((string) $request->input('relationship', '')),
            'statement' => trim((string) $request->input('statement', '')),
            'evidence_note' => trim((string) $request->input('evidence_note', '')),
        ];
        $errors = [];
        if ($data['relationship'] === '' || mb_strlen($data['relationship']) > 120) {
            $errors['relationship'] = '请填写你与逝者的关系（120 字以内）';
        }
        if (mb_strlen($data['statement']) < 20 || mb_strlen($data['statement']) > 2000) {
            $errors['statement'] = '请说明申请理由，20–2000 字';
        }
        if (mb_strlen($data['evidence_note']) > 1000) {
            $errors['evidence_note'] = '证明材料说明不能超过 1000 字';
        }
        if (Claim::pendingForUser((int) $memorial['id'], (int) $user['id'])) {
            $errors['form'] = '你已有一条待处理的申请，请耐心等待审核';
        }
        if (!RateLimiter::hit('claim:' . $user['id'], 3, 86400)) {
            $errors['form'] = '提交过于频繁，请明天再试';
        }

        if ($errors !== []) {
            return View::render('memorial/claim', [
                'title' => '申请管理 · ' . $memorial['title'],
                'memorial' => $memorial,
                'errors' => $errors,
                'old' => $data,
                'noindex' => true,
            ], 'layout', 422);
        }

        $id = Claim::create((int) $memorial['id'], (int) $user['id'], $data['relationship'], $data['statement'], $data['evidence_note']);
        Audit::log('claim.create', 'claim', $id, ['memorial_id' => (int) $memorial['id']]);
        Flash::success('申请已提交。平台会尽快核验并联系你。');
        return Response::redirect('/m/' . $memorial['slug']);
    }

    /**
     * @return array{0: array, 1: ?array}
     */
    private function loadViewable(string $slug): array
    {
        $memorial = Memorial::findBySlug($slug);
        if ($memorial === null) {
            throw new HttpException(404, '纪念馆不存在');
        }
        $user = Auth::user();
        if (!Policy::canView($memorial, $user)) {
            throw new HttpException(404, '纪念馆不存在');
        }
        return [$memorial, $user];
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base !== '') {
            return $base;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
