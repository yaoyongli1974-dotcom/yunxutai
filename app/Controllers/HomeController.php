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
        $base = $this->baseUrl();
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // 静态公开页始终存在，保证 sitemap 不会因为尚无公开纪念馆而为空。
        $urls = '';
        foreach (['/', '/memorials', '/about', '/services', '/privacy', '/terms'] as $path) {
            $urls .= '<url><loc>' . $esc($base . $path) . '</loc></url>';
        }

        foreach (Memorial::indexable() as $memorial) {
            $urls .= '<url><loc>' . $esc($base . '/m/' . $memorial['slug']) . '</loc>'
                . '<lastmod>' . date('Y-m-d', strtotime((string) $memorial['updated_at'])) . '</lastmod>'
                . '</url>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';

        return Response::text($xml)->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(Request $request): Response
    {
        $base = $this->baseUrl();

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /manage',
            'Disallow: /account',
            'Disallow: /dashboard',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /2fa',
            'Disallow: /invitations',
            'Disallow: /report',
            'Disallow: /api/',
            'Disallow: /media/',
            '',
            'Sitemap: ' . $base . '/sitemap.xml',
            '',
        ];

        return Response::text(implode("\n", $lines));
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
