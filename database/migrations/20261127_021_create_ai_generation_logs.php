<?php
declare(strict_types=1);

return [
    'version' => '20261127_021',
    'name' => 'create_ai_generation_logs',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS ai_generation_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workflow_id BIGINT UNSIGNED NOT NULL,
    family_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    model VARCHAR(100) NOT NULL DEFAULT \'\',
    prompt_version VARCHAR(40) NOT NULL,
    input_refs JSON NOT NULL,
    output_version VARCHAR(40) NOT NULL DEFAULT \'v1\',
    review_status ENUM(\'pending\',\'confirmed\',\'rejected\') NOT NULL DEFAULT \'pending\',
    created_by BIGINT UNSIGNED NOT NULL,
    edited_by BIGINT UNSIGNED DEFAULT NULL,
    edited_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_generation_workflow (workflow_id),
    KEY idx_ai_generation_family (family_id, created_at),
    CONSTRAINT fk_ai_generation_workflow FOREIGN KEY (workflow_id) REFERENCES ai_workflows (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_generation_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_generation_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
