<?php
declare(strict_types=1);

return [
    'version' => '20261128_007',
    'name' => 'ai_workflow_deadlines',
    'sql' => [
        'ALTER TABLE ai_workflows ADD COLUMN deadline_at DATETIME DEFAULT NULL',
        'ALTER TABLE ai_workflows ADD COLUMN workflow_timeout_seconds INT UNSIGNED DEFAULT NULL',
        'ALTER TABLE ai_workflows ADD COLUMN step_timeout_seconds INT UNSIGNED DEFAULT NULL',
        'ALTER TABLE ai_workflows ADD KEY idx_ai_workflow_deadline (deadline_at)',
    ],
];
