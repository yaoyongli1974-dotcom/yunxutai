<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Memorial;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $memorials = Memorial::forUser((int) $user['id']);

        return View::render('account/dashboard', [
            'title' => '我的纪念馆',
            'memorials' => $memorials,
            'user' => $user,
        ]);
    }
}
