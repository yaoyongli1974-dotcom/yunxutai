<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout', int $status = 200): Response
    {
        $content = self::capture($template, $data);

        if ($layout !== null) {
            $content = self::capture($layout, [...$data, 'content' => $content]);
        }

        return Response::html($content, $status);
    }

    public static function partial(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    private static function capture(string $template, array $data): string
    {
        $path = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
