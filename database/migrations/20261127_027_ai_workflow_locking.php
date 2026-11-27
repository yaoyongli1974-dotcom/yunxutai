<?php
declare(strict_types=1);

return [
    'version' => '20261127_027',
    'name' => 'ai_workflow_locking',
    'sql' => [
        'ALTER TABLE ai_workflows
            ADD COLUMN lock_version BIGINT NOT NULL DEFAULT 0,
            ADD COLUMN heartbeat_at DATETIME DEFAULT NULL,
            ADD COLUMN last_error_at DATETIME DEFAULT NULL',
    ],
];
