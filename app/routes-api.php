<?php
declare(strict_types=1);

use App\Api\Api;
use App\Api\Controllers\AuthController;
use App\Api\Controllers\ClaimController;
use App\Api\Controllers\ManageController;
use App\Api\Controllers\MediaController;
use App\Api\Controllers\MemberController;
use App\Api\Controllers\MemorialController;
use App\Api\Controllers\TributeController;
use App\Core\Router;

/**
 * 小程序 JSON API（v1）。
 * public/index.php 以 is_file + require 取回闭包，并注入主 Router。
 * 每个端点经 Api::route() 包装，异常统一转 JSON（ApiException / internal_error）。
 */
return static function (Router $router): void {
    // 认证（无需 token）
    $router->post('/api/v1/auth/token', Api::route([AuthController::class, 'token']));
    $router->post('/api/v1/auth/wechat', Api::route([AuthController::class, 'wechat']));

    // 认证（需 Bearer token）
    $router->post('/api/v1/auth/logout', Api::route([AuthController::class, 'logout']), ['api-auth']);
    $router->get('/api/v1/me', Api::route([AuthController::class, 'me']), ['api-auth']);

    // 签名媒体（小程序 image/video 标签无法带 Authorization 头，走短时签名 URL）
    $router->get('/api/v1/media/{id}', Api::route([MediaController::class, 'show']));

    // 内容读取
    $router->get('/api/v1/memorials', Api::route([MemorialController::class, 'index']));
    $router->get('/api/v1/memorials/{slug}', Api::route([MemorialController::class, 'show']));
    $router->get('/api/v1/memorials/{slug}/articles/{id}', Api::route([MemorialController::class, 'article']));

    // 互动
    $router->post('/api/v1/memorials/{slug}/tribute', Api::route([TributeController::class, 'tribute']), ['api-auth']);
    $router->get('/api/v1/memorials/{slug}/messages', Api::route([TributeController::class, 'messages']));
    $router->post('/api/v1/memorials/{slug}/message', Api::route([TributeController::class, 'message']), ['api-auth']);

    // 申领与管理
    $router->post('/api/v1/memorials/{slug}/claim', Api::route([ClaimController::class, 'store']), ['api-auth']);
    $router->get('/api/v1/manage/memorials', Api::route([ManageController::class, 'index']), ['api-auth']);
    $router->post('/api/v1/manage/memorials', Api::route([ManageController::class, 'store']), ['api-auth']);

    // 馆主成员管理
    $router->get('/api/v1/manage/memorials/{id}/members', Api::route([MemberController::class, 'index']), ['api-auth']);
    $router->post('/api/v1/manage/memorials/{id}/members/invite', Api::route([MemberController::class, 'invite']), ['api-auth']);
    $router->post('/api/v1/manage/memorials/{id}/members/{memberId}/role', Api::route([MemberController::class, 'updateRole']), ['api-auth']);
    $router->post('/api/v1/manage/memorials/{id}/members/{memberId}/remove', Api::route([MemberController::class, 'remove']), ['api-auth']);
    $router->post('/api/v1/manage/memorials/{id}/invitations/{invitationId}/revoke', Api::route([MemberController::class, 'revokeInvitation']), ['api-auth']);
};
