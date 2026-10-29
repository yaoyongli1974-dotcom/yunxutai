<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Memorial;
use App\Models\Stats;

final class HomeController
{
    public function index(Request $request): Response
    {
        return View::render('home', [
            'title' => '',
            'memorials' => Memorial::recentPublic(4),
            'summary' => Stats::publicSummary(),
        ]);
    }

    public function memorials(Request $request): Response
    {
        $query = trim((string) ($request->query['q'] ?? ''));
        if (mb_strlen($query) > 60) {
            $query = mb_substr($query, 0, 60);
        }

        $paginator = Paginator::fromRequest($request, Memorial::publicCount($query), 12);
        $items = Memorial::publicPaginate($query, $paginator->perPage, $paginator->offset);

        return View::render('memorial/index', [
            'title' => $query !== '' ? '搜索：' . $query : '纪念馆',
            'memorials' => $items,
            'query' => $query,
            'paginator' => $paginator,
            'noindex' => $query !== '',
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($request->server['HTTPS']) && $request->server['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($request->server['HTTP_HOST'] ?? 'localhost');
        }

        $urls = '';
        foreach (Memorial::indexable() as $memorial) {
            $urls .= '<url><loc>' . e($base . '/m/' . $memorial['slug']) . '</loc>'
                . '<lastmod>' . date('Y-m-d', strtotime((string) $memorial['updated_at'])) . '</lastmod>'
                . '</url>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';

        return Response::text($xml)->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
}
