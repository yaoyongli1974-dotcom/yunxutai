<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Models\Media;
use App\Models\Memorial;

final class MediaController
{
    public function show(Request $request, string $id): Response
    {
        $media = Media::find((int) $id);
        if ($media === null || $media['status'] !== 'active') {
            throw new HttpException(404, '文件不存在');
        }

        $memorial = Memorial::find((int) $media['memorial_id']);
        if ($memorial === null || !Policy::canViewMedia($media, $memorial, Auth::user())) {
            throw new HttpException(404, '文件不存在');
        }

        $path = Storage::absolute($media['disk_path']);
        if ($path === null) {
            throw new HttpException(404, '文件不存在');
        }

        $disposition = $media['kind'] === 'document'
            ? 'attachment'
            : 'inline';
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $media['original_name']) ?: 'file';
        $encodedName = rawurlencode($media['original_name']);

        return Response::file($path, $media['mime'], 200, [
            'Content-Disposition' => sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $disposition, $fallbackName, $encodedName),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
