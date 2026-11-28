<?php
declare(strict_types=1);

return [
    'version' => '20261128_008',
    'name' => 'ai_fact_credibility',
    'sql' => [
        "ALTER TABLE memory_facts
            ADD COLUMN fact_kind ENUM('user_provided','family_verified','archive_verified','public_source','ai_inferred','unknown') NOT NULL DEFAULT 'ai_inferred' AFTER source_type",
        'ALTER TABLE memory_facts ADD COLUMN workflow_id BIGINT UNSIGNED DEFAULT NULL AFTER source_id',
        "ALTER TABLE memory_facts
            ADD COLUMN review_status ENUM('pending','approved','rejected','needs_revision') NOT NULL DEFAULT 'pending' AFTER verification_status",
        'ALTER TABLE memory_facts ADD COLUMN review_note VARCHAR(500) DEFAULT NULL AFTER review_status',
        'ALTER TABLE memory_facts ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER review_note',
        'ALTER TABLE memory_facts ADD KEY idx_memory_fact_workflow (workflow_id, id)',
        "CREATE TABLE IF NOT EXISTS ai_fact_revisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fact_id BIGINT UNSIGNED NOT NULL,
            fact_content TEXT NOT NULL,
            verification_status VARCHAR(32) NOT NULL,
            review_status VARCHAR(32) NOT NULL,
            review_note VARCHAR(500) DEFAULT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            actor_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fact_revision_fact (fact_id, id),
            CONSTRAINT fk_fact_revision_fact FOREIGN KEY (fact_id) REFERENCES memory_facts (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
