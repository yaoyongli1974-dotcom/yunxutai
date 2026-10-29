<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Core\MediaSign;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Models\Media;

/**
 * GET /api/v1/media/{id}?exp=&sig= — 面向小程序 <image>/<video> 等无法携带
 * Authorization 头的场景，使用短时签名 URL 输出媒体。
 *
 * 签名即代表馆主/协作者已授权的临时访问凭证（TTL 900s）；
 * 签名无效、过期或文件不可用时一律 404，不区分原因。
 */
final class MediaController
{
    public function show(Request $request, string $id): Response
    {
        $mediaId = (int) $id;
        $exp = (string) ($request->query['exp'] ?? '');
        $sig = (string) ($request->query['sig'] ?? '');

        if (!MediaSign::verify($mediaId, $exp, $sig)) {
            throw ApiException::notFound('文件不存在');
        }

        $media = Media::find($mediaId);
        if ($media === null || $media['status'] !== 'active') {
            throw ApiException::notFound('文件不存在');
        }

        $path = Storage::absolute($media['disk_path']);
        if ($path === null) {
            throw ApiException::notFound('文件不存在');
        }

        $disposition = $media['kind'] === 'document' ? 'attachment' : 'inline';
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $media['original_name']) ?: 'file';
        $encodedName = rawurlencode($media['original_name']);

        return Response::file($path, $media['mime'], 200, [
            'Content-Disposition' => sprintf("%s; filename=\"%s\"; filename*=UTF-8''%s", $disposition, $fallbackName, $encodedName),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
