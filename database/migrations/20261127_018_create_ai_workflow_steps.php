<?php
declare(strict_types=1);

return [
    'version' => '20261127_018',
    'name' => 'create_ai_workflow_steps',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS ai_workflow_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workflow_id BIGINT UNSIGNED NOT NULL,
    step_no INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    status ENUM(\'queued\',\'processing\',\'completed\',\'failed\',\'skipped\') NOT NULL DEFAULT \'queued\',
    input_ref VARCHAR(255) DEFAULT NULL,
    output_ref VARCHAR(255) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_workflow_step (workflow_id, step_no),
    CONSTRAINT fk_ai_step_workflow FOREIGN KEY (workflow_id) REFERENCES ai_workflows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
