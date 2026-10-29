<?php
declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Core\Auth;
use App\Core\Policy;
use App\Models\Memorial;

/**
 * API 控制器公共部分：可见性判定完全复用 App\Core\Policy，
 * 私密/暂停/未核验的馆对无权限者统一 404，不暴露存在性。
 */
abstract class BaseController
{
    protected function currentUser(): ?array
    {
        return Auth::user();
    }

    protected function user(): array
    {
        $user = Auth::user();
        if ($user === null) {
            throw ApiException::unauthorized('请先登录');
        }
        return $user;
    }

    /**
     * 与网页端 MemorialController::loadViewable() 同口径。
     *
     * @return array{0: array, 1: ?array}
     */
    protected function loadViewable(string $slug): array
    {
        $memorial = Memorial::findBySlug($slug);
        $user = Auth::user();
        if ($memorial === null || !Policy::canView($memorial, $user)) {
            throw ApiException::notFound('纪念馆不存在');
        }
        return [$memorial, $user];
    }

    /**
     * 与网页端 TributeController::loadActionable() 同口径。
     *
     * @return array{0: array, 1: array}
     */
    protected function loadPostable(string $slug): array
    {
        $memorial = Memorial::findBySlug($slug);
        if ($memorial === null) {
            throw ApiException::notFound('纪念馆不存在');
        }
        $user = $this->user();
        if (!Policy::canPost($memorial, $user)) {
            throw ApiException::forbidden('无法在该纪念馆留言或祭奠');
        }
        return [$memorial, $user];
    }
}
