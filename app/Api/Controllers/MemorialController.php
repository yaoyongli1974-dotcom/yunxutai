<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Api;
use App\Api\ApiException;
use App\Api\Presenter;
use App\Core\Db;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Models\Article;
use App\Models\LifeEvent;
use App\Models\Media;

final class MemorialController extends BaseController
{
    /**
     * GET /api/v1/memorials — 可收录的公开馆列表。
     * 口径：active + public + verified + allow_search_index（后者网页列表未加，按 API 契约补上）。
     */
    public function index(Request $request): Response
    {
        [$page, $perPage] = Api::page($request, 12, 20);
        $offset = ($page - 1) * $perPage;

        $query = trim((string) ($request->query['q'] ?? ''));
        if (mb_strlen($query) > 60) {
            $query = mb_substr($query, 0, 60);
        }

        $where = "m.status = 'active' AND m.visibility = 'public'
                  AND m.verification_status = 'verified' AND m.allow_search_index = 1";
        $params = [];
        if ($query !== '') {
            $where .= ' AND (d.full_name LIKE ? OR m.title LIKE ? OR m.summary LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }

        $total = (int) Db::value(
            "SELECT COUNT(*) FROM memorials m JOIN deceased d ON d.id = m.deceased_id WHERE {$where}",
            $params
        );
        $rows = Db::fetchAll(
            "SELECT m.*, d.full_name, d.birth_date, d.death_date, d.birth_place, d.cover_media_id
             FROM memorials m JOIN deceased d ON d.id = m.deceased_id
             WHERE {$where}
             ORDER BY COALESCE(m.published_at, m.created_at) DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return Api::ok([
            'items' => array_map(
                static fn (array $row): array => Presenter::memorialSummary($row),
                $rows
            ),
            'pagination' => Api::pagination($total, $page, $perPage),
        ]);
    }

    /**
     * GET /api/v1/memorials/{slug} — 详情。
     * 权限与网页 show 完全一致（Policy::canView），不可见一律 404。
     */
    public function show(Request $request, string $slug): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $role = Policy::role($memorial, $user);
        $isMember = $role !== null;
        $memorialId = (int) $memorial['id'];

        $articles = array_map(
            static fn (array $article): array => Presenter::articleListItem($article),
            Article::publishedFor($memorialId)
        );
        $media = array_map(
            static fn (array $item): array => Presenter::media($item),
            Media::visible($memorialId, $isMember)
        );
        $life = array_map(
            static fn (array $event): array => Presenter::life($event),
            LifeEvent::forMemorial($memorialId)
        );

        return Api::ok([
            'summary' => [
                ...Presenter::memorialSummary($memorial),
                'role' => $role,
                'is_member' => $isMember,
                'can_post' => Policy::canPost($memorial, $user),
            ],
            'profile' => Presenter::profile($memorial),
            'life' => $life,
            'articles' => $articles,
            'media' => $media,
        ]);
    }

    /**
     * GET /api/v1/memorials/{slug}/articles/{id}
     * 可见性同网页 article()：published 或具备编辑权限。
     */
    public function article(Request $request, string $slug, string $id): Response
    {
        [$memorial, $user] = $this->loadViewable($slug);
        $article = Article::find((int) $id);
        if ($article === null || (int) $article['memorial_id'] !== (int) $memorial['id']) {
            throw ApiException::notFound('文章不存在');
        }
        if ($article['status'] !== 'published' && !Policy::canEdit($memorial, $user)) {
            throw ApiException::notFound('文章不存在');
        }

        return Api::ok([
            'id' => (int) $article['id'],
            'title' => (string) $article['title'],
            'summary' => excerpt_text((string) $article['content'], 120),
            'content_html' => Presenter::contentHtml((string) $article['content']),
            'status' => (string) $article['status'],
            'author_name' => $article['author_name'] ?? null,
            'published_at' => $article['published_at'] ?? null,
            'updated_at' => $article['updated_at'] ?? null,
        ]);
    }
}
