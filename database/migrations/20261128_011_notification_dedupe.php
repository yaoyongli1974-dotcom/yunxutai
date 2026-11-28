<?php
declare(strict_types=1);

return [
    'version' => '20261128_011',
    'name' => 'notification_dedupe',
    'sql' => [
        'ALTER TABLE notifications
            ADD COLUMN dedupe_key CHAR(64) DEFAULT NULL,
            ADD UNIQUE KEY uq_notification_dedupe (dedupe_key)',
    ],
];
