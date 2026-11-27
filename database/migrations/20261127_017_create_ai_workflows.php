<?php
declare(strict_types=1);

return [
    'version' => '20261127_017',
    'name' => 'create_ai_workflows',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS ai_workflows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    workflow_type VARCHAR(40) NOT NULL,
    status ENUM(\'queued\',\'processing\',\'completed\',\'failed\',\'cancelled\') NOT NULL DEFAULT \'queued\',
    idempotency_key VARCHAR(64) NOT NULL,
    input_json JSON NOT NULL,
    output_json JSON DEFAULT NULL,
    current_step VARCHAR(60) DEFAULT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT \'demo\',
    prompt_version VARCHAR(40) NOT NULL DEFAULT \'v1\',
    error_message VARCHAR(500) DEFAULT NULL,
    failure_class VARCHAR(40) DEFAULT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_retries INT UNSIGNED NOT NULL DEFAULT 3,
    run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_until DATETIME DEFAULT NULL,
    locked_by VARCHAR(64) DEFAULT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_workflow_idem (family_id, workflow_type, idempotency_key),
    KEY idx_ai_workflow_status (status, run_after),
    KEY idx_ai_workflow_family (family_id, created_at),
    CONSTRAINT fk_ai_workflow_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_workflow_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
