<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Core\Auth;
use App\Core\MediaSign;
use App\Core\Policy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Models\Media;
use App\Models\Memorial;

/**
 * GET /api/v1/media/{id}?exp=&sig= — 面向小程序 <image>/<video> 等无法携带
 * Authorization 头的场景，使用短时签名 URL 输出媒体。
 *
 * 签名是签发时授权生成的短时凭证，并绑定媒体当前可见性：可见性下调后旧签名立即失效；
 * 若请求同时带有用户身份（Web Session 或 Bearer 注入），仍按当前权限复核。
 * 签名无效、过期、无权访问或文件不可用时一律 404，不区分原因。
 */
final class MediaController
{
    public function show(Request $request, string $id): Response
    {
        $mediaId = (int) $id;
        $exp = (string) ($request->query['exp'] ?? '');
        $sig = (string) ($request->query['sig'] ?? '');

        $media = Media::find($mediaId);
        if ($media === null || $media['status'] !== 'active') {
            throw ApiException::notFound('文件不存在');
        }

        // 可见性绑定校验：授权下调后，签发时生成的旧签名立即失效。
        if (!MediaSign::verify($mediaId, $exp, $sig, (string) ($media['visibility'] ?? 'visitors'))) {
            throw ApiException::notFound('文件不存在');
        }

        // 有用户身份时按当前权限复核；小程序 <image> 无法携带身份，签名即为其短时凭证。
        $user = Auth::user();
        if ($user !== null) {
            $memorial = Memorial::find((int) $media['memorial_id']);
            if ($memorial === null || !Policy::canViewMedia($media, $memorial, $user)) {
                throw ApiException::notFound('文件不存在');
            }
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
            'Cache-Control' => 'no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
