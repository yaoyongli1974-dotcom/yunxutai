<?php
declare(strict_types=1);

return [
    'version' => '20261127_019',
    'name' => 'create_memory_facts',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS memory_facts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED DEFAULT NULL,
    fact_type VARCHAR(40) NOT NULL,
    fact_content TEXT NOT NULL,
    source_type ENUM(\'user_input\',\'oral_history\',\'archive_ocr\',\'ai_extraction\',\'family_confirmation\',\'imported\') NOT NULL,
    source_id BIGINT UNSIGNED DEFAULT NULL,
    source_location VARCHAR(255) DEFAULT NULL,
    confidence DECIMAL(4,3) NOT NULL DEFAULT 0.500,
    verification_status ENUM(\'pending\',\'confirmed\',\'disputed\',\'rejected\') NOT NULL DEFAULT \'pending\',
    submitted_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_memory_fact_family (family_id, verification_status, created_at),
    KEY idx_memory_fact_person (person_id),
    CONSTRAINT fk_memory_fact_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_memory_fact_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE SET NULL,
    CONSTRAINT fk_memory_fact_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_memory_fact_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
