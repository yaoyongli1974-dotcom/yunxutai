<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\NotificationService;

final class NotificationController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $userId = (int) ($user['id'] ?? 0);
        return View::render('notification/index', [
            'title' => '通知中心 · 云叙台',
            'noindex' => true,
            'notifications' => NotificationService::forUser($userId),
            'preferences' => NotificationService::preferences($userId),
            'types' => NotificationService::TYPES,
        ]);
    }

    public function markRead(Request $request, string $id): Response
    {
        Csrf::verify($request);
        NotificationService::markRead((int) (Auth::user()['id'] ?? 0), (int) $id);
        return Response::redirect(url('/notifications'));
    }

    public function markAllRead(Request $request): Response
    {
        Csrf::verify($request);
        NotificationService::markAllRead((int) (Auth::user()['id'] ?? 0));
        Flash::success('已全部标为已读。');
        return Response::redirect(url('/notifications'));
    }

    public function savePreference(Request $request): Response
    {
        Csrf::verify($request);
        $userId = (int) (Auth::user()['id'] ?? 0);
        NotificationService::setPreference($userId, (string) $request->input('type', ''),
            (string) $request->input('in_app') === '1', (string) $request->input('email') === '1');
        Flash::success('通知偏好已保存。');
        return Response::redirect(url('/notifications'));
    }
}
