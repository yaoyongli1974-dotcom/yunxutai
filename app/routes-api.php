<?php
declare(strict_types=1);

use App\Api\Api;
use App\Api\Controllers\AuthController;
use App\Api\Controllers\ClaimController;
use App\Api\Controllers\FamilyV2Controller;
use App\Api\Controllers\ManageController;
use App\Api\Controllers\MediaController;
use App\Api\Controllers\MemberController;
use App\Api\Controllers\MemorialController;
use App\Api\Controllers\ReportController;
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

    // 投诉与举报（活人被祭拜等紧急场景；无需验证码，按 IP 限流）
    $router->post('/api/v1/reports', Api::route([ReportController::class, 'store']), ['api-auth']);

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

    // 2.0 家族业务：与 Web 端共用 Service + Policy，全部要求 Bearer token。

    $router->get('/api/v1/families', Api::route([FamilyV2Controller::class, 'families']), ['api-auth']);
    $router->post('/api/v1/families', Api::route([FamilyV2Controller::class, 'createFamily']), ['api-auth']);
    $router->post('/api/v1/invitations/accept', Api::route([FamilyV2Controller::class, 'acceptInvitation']), ['api-auth']);
    $router->get('/api/v1/persons/{id}', Api::route([FamilyV2Controller::class, 'person']), ['api-auth']);
    $router->get('/api/v1/archives/{id}', Api::route([FamilyV2Controller::class, 'archive']), ['api-auth']);
    $router->get('/api/v1/ai/workflows/{id}', Api::route([FamilyV2Controller::class, 'workflow']), ['api-auth']);
    $router->post('/api/v1/ai/workflows/{id}/cancel', Api::route([FamilyV2Controller::class, 'cancelWorkflow']), ['api-auth']);
    $router->post('/api/v1/ai/facts/{id}/review', Api::route([FamilyV2Controller::class, 'reviewFact']), ['api-auth']);
    $router->get('/api/v1/notifications', Api::route([FamilyV2Controller::class, 'notifications']), ['api-auth']);
    $router->post('/api/v1/notifications/read-all', Api::route([FamilyV2Controller::class, 'readAllNotifications']), ['api-auth']);
    $router->post('/api/v1/notifications/{id}/read', Api::route([FamilyV2Controller::class, 'readNotification']), ['api-auth']);

    $router->get('/api/v1/families/{id}', Api::route([FamilyV2Controller::class, 'family']), ['api-auth']);
    $router->get('/api/v1/families/{id}/members', Api::route([FamilyV2Controller::class, 'members']), ['api-auth']);
    $router->post('/api/v1/families/{id}/invitations', Api::route([FamilyV2Controller::class, 'invite']), ['api-auth']);
    $router->get('/api/v1/families/{id}/persons', Api::route([FamilyV2Controller::class, 'persons']), ['api-auth']);
    $router->post('/api/v1/families/{id}/persons', Api::route([FamilyV2Controller::class, 'createPerson']), ['api-auth']);
    $router->get('/api/v1/families/{id}/genealogy', Api::route([FamilyV2Controller::class, 'genealogy']), ['api-auth']);
    $router->post('/api/v1/families/{id}/relationships', Api::route([FamilyV2Controller::class, 'submitRelationship']), ['api-auth']);
    $router->post('/api/v1/families/{id}/relationships/{relationId}/review', Api::route([FamilyV2Controller::class, 'reviewRelationship']), ['api-auth']);
    $router->get('/api/v1/families/{id}/archives', Api::route([FamilyV2Controller::class, 'archives']), ['api-auth']);
    $router->post('/api/v1/families/{id}/archives', Api::route([FamilyV2Controller::class, 'createArchive']), ['api-auth']);
    $router->post('/api/v1/families/{id}/ai/workflows', Api::route([FamilyV2Controller::class, 'createWorkflow']), ['api-auth']);
    $router->get('/api/v1/families/{id}/ai/sessions', Api::route([FamilyV2Controller::class, 'oralSessions']), ['api-auth']);
    $router->post('/api/v1/families/{id}/ai/sessions', Api::route([FamilyV2Controller::class, 'createOralSession']), ['api-auth']);
    $router->get('/api/v1/ai/sessions/{id}', Api::route([FamilyV2Controller::class, 'oralSession']), ['api-auth']);
    $router->post('/api/v1/ai/sessions/{id}/consent', Api::route([FamilyV2Controller::class, 'sessionConsent']), ['api-auth']);
    $router->post('/api/v1/ai/sessions/{id}/transcribe', Api::route([FamilyV2Controller::class, 'transcribeSession']), ['api-auth']);
    $router->get('/api/v1/ai/sessions/{id}/segments', Api::route([FamilyV2Controller::class, 'sessionSegments']), ['api-auth']);
    $router->post('/api/v1/ai/sessions/{id}/segments', Api::route([FamilyV2Controller::class, 'addSegment']), ['api-auth']);
    $router->get('/api/v1/ai/sessions/{id}/export', Api::route([FamilyV2Controller::class, 'exportSession']), ['api-auth']);
    $router->post('/api/v1/ai/segments/{id}/correct', Api::route([FamilyV2Controller::class, 'correctSegment']), ['api-auth']);
    $router->post('/api/v1/ai/segments/{id}/review', Api::route([FamilyV2Controller::class, 'reviewSegment']), ['api-auth']);
    $router->get('/api/v1/families/{id}/events', Api::route([FamilyV2Controller::class, 'events']), ['api-auth']);
    $router->post('/api/v1/families/{id}/events', Api::route([FamilyV2Controller::class, 'createEvent']), ['api-auth']);
    $router->get('/api/v1/families/{id}/exhibitions', Api::route([FamilyV2Controller::class, 'exhibitions']), ['api-auth']);
    $router->post('/api/v1/families/{id}/exhibitions', Api::route([FamilyV2Controller::class, 'createExhibition']), ['api-auth']);
    $router->post('/api/v1/exhibitions/{id}/publish', Api::route([FamilyV2Controller::class, 'publishExhibition']), ['api-auth']);
};
