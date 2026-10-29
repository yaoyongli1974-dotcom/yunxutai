<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Stats
{
    public static function adminSummary(): array
    {
        $memorials = Memorial::statusCounts();
        return [
            'users' => (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'user'"),
            'memorials_total' => array_sum($memorials),
            'memorials_active' => $memorials['active'],
            'memorials_draft' => $memorials['draft'],
            'memorials_suspended' => $memorials['suspended'],
            'pending_messages' => Message::pendingCount(),
            'pending_articles' => Article::pendingCount(),
            'pending_verifications' => Verification::count('pending'),
            'verified_memorials' => (int) Db::value("SELECT COUNT(*) FROM memorials WHERE verification_status = 'verified'"),
            'open_reports' => Report::count('open'),
            'open_claims' => Claim::count('open'),
            'new_services' => ServiceRequest::count('new'),
            'open_data_requests' => DataRequest::count('open'),
            'tributes_total' => (int) Db::value('SELECT COUNT(*) FROM tributes'),
        ];
    }

    public static function publicSummary(): array
    {
        return [
            'memorials' => (int) Db::value("SELECT COUNT(*) FROM memorials WHERE status = 'active' AND verification_status = 'verified'"),
            'tributes' => (int) Db::value("SELECT COUNT(*) FROM tributes WHERE status = 'visible'"),
            'messages' => (int) Db::value("SELECT COUNT(*) FROM messages WHERE status = 'visible'"),
        ];
    }
}
