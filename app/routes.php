<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\Admin\ClaimController as AdminClaimController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\Admin\DataRequestController as AdminDataRequestController;
use App\Controllers\Admin\AiJobController as AdminAiJobController;
use App\Controllers\Admin\AiServiceController as AdminAiServiceController;
use App\Controllers\Admin\LogController as AdminLogController;
use App\Controllers\Admin\MemorialController as AdminMemorialController;
use App\Controllers\Admin\ModerationController as AdminModerationController;
use App\Controllers\Admin\ReportController as AdminReportController;
use App\Controllers\Admin\ServiceController as AdminServiceController;
use App\Controllers\Admin\UserController as AdminUserController;
use App\Controllers\Admin\VerificationController as AdminVerificationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\InvitationController;
use App\Controllers\Manage\AiController as ManageAiController;
use App\Controllers\Manage\ContentController;
use App\Controllers\Manage\MemberController;
use App\Controllers\Manage\MemorialController as ManageMemorialController;
use App\Controllers\Manage\ModerationController as ManageModerationController;
use App\Controllers\Manage\VerificationController as ManageVerificationController;
use App\Controllers\MediaController;
use App\Controllers\MemorialController;
use App\Controllers\PageController;
use App\Controllers\ReportController;
use App\Controllers\TributeController;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */
$router = new Router();

// 公共页面
$router->get('/', [HomeController::class, 'index']);
$router->get('/memorials', [HomeController::class, 'memorials']);
$router->get('/m/{slug}', [MemorialController::class, 'show']);
$router->get('/m/{slug}/gallery', [MemorialController::class, 'gallery']);
$router->get('/m/{slug}/life', [MemorialController::class, 'life']);
$router->get('/m/{slug}/articles', [MemorialController::class, 'articles']);
$router->get('/m/{slug}/articles/{id}', [MemorialController::class, 'article']);
$router->get('/m/{slug}/tributes', [MemorialController::class, 'tributes']);
$router->get('/m/{slug}/messages', [MemorialController::class, 'messages']);
$router->get('/media/{id}', [MediaController::class, 'show']);
$router->get('/sitemap.xml', [HomeController::class, 'sitemap']);

$router->get('/about', [PageController::class, 'about']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/terms', [PageController::class, 'terms']);
$router->get('/services', [PageController::class, 'services']);
$router->post('/services', [PageController::class, 'submitService']);

// 举报
$router->get('/report', [ReportController::class, 'create']);
$router->post('/report', [ReportController::class, 'store']);
$router->get('/report/success', [ReportController::class, 'success']);

// 注册与登录
$router->get('/register', [AuthController::class, 'showRegister'], ['guest']);
$router->post('/register', [AuthController::class, 'register'], ['guest']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth']);

// 登录用户
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
$router->get('/account', [AccountController::class, 'show'], ['auth']);
$router->post('/account/profile', [AccountController::class, 'updateProfile'], ['auth']);
$router->post('/account/password', [AccountController::class, 'updatePassword'], ['auth']);
$router->post('/account/data-request', [AccountController::class, 'requestData'], ['auth']);

// 祭奠与留言
$router->post('/m/{slug}/tributes', [TributeController::class, 'store'], ['auth']);
$router->post('/m/{slug}/messages', [TributeController::class, 'message'], ['auth']);
$router->get('/m/{slug}/claim', [MemorialController::class, 'claimForm'], ['auth']);
$router->post('/m/{slug}/claim', [MemorialController::class, 'claimStore'], ['auth']);

// 邀请
$router->get('/invitations/{token}', [InvitationController::class, 'show'], ['auth']);
$router->post('/invitations/{token}', [InvitationController::class, 'accept'], ['auth']);

// 纪念馆管理
$router->get('/manage', static fn () => Response::redirect('/dashboard'), ['auth']);
$router->get('/manage/memorials/new', [ManageMemorialController::class, 'create'], ['auth']);
$router->post('/manage/memorials', [ManageMemorialController::class, 'store'], ['auth']);
$router->get('/manage/memorials/{id}', [ManageMemorialController::class, 'overview'], ['auth']);
$router->get('/manage/memorials/{id}/profile', [ManageMemorialController::class, 'profile'], ['auth']);
$router->post('/manage/memorials/{id}/profile', [ManageMemorialController::class, 'updateProfile'], ['auth']);
$router->get('/manage/memorials/{id}/settings', [ManageMemorialController::class, 'settings'], ['auth']);
$router->post('/manage/memorials/{id}/settings', [ManageMemorialController::class, 'updateSettings'], ['auth']);
$router->get('/manage/memorials/{id}/verification', [ManageVerificationController::class, 'show'], ['auth']);
$router->post('/manage/memorials/{id}/verification', [ManageVerificationController::class, 'store'], ['auth']);
$router->get('/manage/memorials/{id}/ai', [ManageAiController::class, 'index'], ['auth']);
$router->post('/manage/memorials/{id}/ai/jobs', [ManageAiController::class, 'store'], ['auth']);
$router->get('/manage/memorials/{id}/ai/jobs/{jobId}', [ManageAiController::class, 'showJob'], ['auth']);
$router->post('/manage/memorials/{id}/ai/jobs/{jobId}/retry', [ManageAiController::class, 'retry'], ['auth']);
$router->post('/manage/memorials/{id}/publish', [ManageMemorialController::class, 'publish'], ['auth']);
$router->post('/manage/memorials/{id}/pause', [ManageMemorialController::class, 'pause'], ['auth']);
$router->post('/manage/memorials/{id}/resume', [ManageMemorialController::class, 'resume'], ['auth']);

$router->get('/manage/memorials/{id}/life', [ContentController::class, 'life'], ['auth']);
$router->post('/manage/memorials/{id}/life', [ContentController::class, 'lifeStore'], ['auth']);
$router->post('/manage/memorials/{id}/life/{eventId}/update', [ContentController::class, 'lifeUpdate'], ['auth']);
$router->post('/manage/memorials/{id}/life/{eventId}/delete', [ContentController::class, 'lifeDelete'], ['auth']);

$router->get('/manage/memorials/{id}/articles', [ContentController::class, 'articles'], ['auth']);
$router->post('/manage/memorials/{id}/articles', [ContentController::class, 'articleStore'], ['auth']);
$router->get('/manage/memorials/{id}/articles/{articleId}', [ContentController::class, 'articleEdit'], ['auth']);
$router->post('/manage/memorials/{id}/articles/{articleId}', [ContentController::class, 'articleUpdate'], ['auth']);
$router->post('/manage/memorials/{id}/articles/{articleId}/delete', [ContentController::class, 'articleDelete'], ['auth']);

$router->get('/manage/memorials/{id}/media', [ContentController::class, 'media'], ['auth']);
$router->post('/manage/memorials/{id}/media', [ContentController::class, 'mediaStore'], ['auth']);
$router->post('/manage/memorials/{id}/media/{mediaId}/delete', [ContentController::class, 'mediaDelete'], ['auth']);
$router->post('/manage/memorials/{id}/media/{mediaId}/hide', [ContentController::class, 'mediaHide'], ['auth']);
$router->post('/manage/memorials/{id}/media/{mediaId}/show', [ContentController::class, 'mediaShow'], ['auth']);
$router->post('/manage/memorials/{id}/media/{mediaId}/visibility', [ContentController::class, 'mediaVisibility'], ['auth']);
$router->post('/manage/memorials/{id}/media/{mediaId}/cover', [ContentController::class, 'mediaCover'], ['auth']);

$router->get('/manage/memorials/{id}/members', [MemberController::class, 'index'], ['auth']);
$router->post('/manage/memorials/{id}/members/invite', [MemberController::class, 'invite'], ['auth']);
$router->post('/manage/memorials/{id}/members/{memberId}/role', [MemberController::class, 'updateRole'], ['auth']);
$router->post('/manage/memorials/{id}/members/{memberId}/remove', [MemberController::class, 'revoke'], ['auth']);
$router->post('/manage/memorials/{id}/invitations/{invitationId}/revoke', [MemberController::class, 'revokeInvitation'], ['auth']);

$router->get('/manage/memorials/{id}/moderation', [ManageModerationController::class, 'index'], ['auth']);
$router->post('/manage/memorials/{id}/moderation/messages/{messageId}', [ManageModerationController::class, 'messageStatus'], ['auth']);
$router->post('/manage/memorials/{id}/moderation/tributes/{tributeId}', [ManageModerationController::class, 'tributeStatus'], ['auth']);

// 平台运营后台
$router->get('/admin', [AdminDashboardController::class, 'index'], ['admin']);
$router->get('/admin/memorials', [AdminMemorialController::class, 'index'], ['admin']);
$router->get('/admin/memorials/{id}', [AdminMemorialController::class, 'show'], ['admin']);
$router->post('/admin/memorials/{id}/suspend', [AdminMemorialController::class, 'suspend'], ['admin']);
$router->post('/admin/memorials/{id}/restore', [AdminMemorialController::class, 'restore'], ['admin']);

$router->get('/admin/verifications', [AdminVerificationController::class, 'index'], ['admin']);
$router->get('/admin/verifications/{id}', [AdminVerificationController::class, 'show'], ['admin']);
$router->get('/admin/verifications/{id}/document', [AdminVerificationController::class, 'document'], ['admin']);
$router->post('/admin/verifications/{id}/review', [AdminVerificationController::class, 'review'], ['admin']);

$router->get('/admin/moderation', [AdminModerationController::class, 'index'], ['admin']);
$router->post('/admin/moderation/messages/{id}', [AdminModerationController::class, 'messageStatus'], ['admin']);
$router->post('/admin/moderation/articles/{id}', [AdminModerationController::class, 'articleStatus'], ['admin']);
$router->post('/admin/moderation/media/{id}', [AdminModerationController::class, 'mediaStatus'], ['admin']);

$router->get('/admin/reports', [AdminReportController::class, 'index'], ['admin']);
$router->get('/admin/reports/{id}', [AdminReportController::class, 'show'], ['admin']);
$router->post('/admin/reports/{id}/resolve', [AdminReportController::class, 'resolve'], ['admin']);

$router->get('/admin/claims', [AdminClaimController::class, 'index'], ['admin']);
$router->post('/admin/claims/{id}', [AdminClaimController::class, 'handle'], ['admin']);

$router->get('/admin/data-requests', [AdminDataRequestController::class, 'index'], ['admin']);
$router->post('/admin/data-requests/{id}', [AdminDataRequestController::class, 'handle'], ['admin']);

$router->get('/admin/services', [AdminServiceController::class, 'index'], ['admin']);
$router->post('/admin/services/{id}', [AdminServiceController::class, 'updateStatus'], ['admin']);

$router->get('/admin/ai-services', [AdminAiServiceController::class, 'index'], ['admin']);
$router->post('/admin/ai-services/{id}', [AdminAiServiceController::class, 'update'], ['admin']);
$router->get('/admin/ai-jobs', [AdminAiJobController::class, 'index'], ['admin']);
$router->post('/admin/ai-jobs/{id}/retry', [AdminAiJobController::class, 'retry'], ['admin']);

$router->get('/admin/users', [AdminUserController::class, 'index'], ['admin']);
$router->post('/admin/users/{id}/status', [AdminUserController::class, 'updateStatus'], ['admin']);

$router->get('/admin/logs', [AdminLogController::class, 'index'], ['admin']);

return $router;
