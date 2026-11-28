<?php
declare(strict_types=1);

return [
    'version' => '20261128_006',
    'name' => 'ai_workflow_step_state_machine',
    'sql' => [
        // 兼容既有 queued/processing 前端标签；新增真实执行与人工审核状态。
        "ALTER TABLE ai_workflow_steps
            MODIFY status ENUM('queued','processing','completed','failed','skipped','cancelled','awaiting_review','approved','rejected') NOT NULL DEFAULT 'queued'",
        'ALTER TABLE ai_workflow_steps ADD COLUMN step_attempt INT UNSIGNED NOT NULL DEFAULT 0',
        'ALTER TABLE ai_workflow_steps ADD COLUMN execution_id CHAR(32) DEFAULT NULL',
        'ALTER TABLE ai_workflow_steps ADD COLUMN step_error_code VARCHAR(64) DEFAULT NULL',
        'ALTER TABLE ai_workflow_steps ADD COLUMN step_updated_at DATETIME DEFAULT NULL',
        "CREATE TABLE IF NOT EXISTS ai_workflow_step_transitions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id BIGINT UNSIGNED NOT NULL,
            step_id BIGINT UNSIGNED DEFAULT NULL,
            step_name VARCHAR(60) DEFAULT NULL,
            previous_status VARCHAR(32) DEFAULT NULL,
            new_status VARCHAR(32) NOT NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'worker',
            actor_id BIGINT UNSIGNED DEFAULT NULL,
            worker_id VARCHAR(128) DEFAULT NULL,
            execution_id CHAR(32) DEFAULT NULL,
            attempt INT UNSIGNED NOT NULL DEFAULT 0,
            error_code VARCHAR(64) DEFAULT NULL,
            request_id CHAR(32) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_step_transition_workflow (workflow_id, id),
            KEY idx_step_transition_step (step_id, id),
            CONSTRAINT fk_step_transition_workflow FOREIGN KEY (workflow_id) REFERENCES ai_workflows (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
