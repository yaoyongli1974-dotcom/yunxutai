<?php
declare(strict_types=1);

return [
    'version' => '20261127_007',
    'name' => 'create_person_relationships',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS person_relationships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_a_id BIGINT UNSIGNED NOT NULL,
            person_b_id BIGINT UNSIGNED NOT NULL,
            relationship_type ENUM(\'parent\',\'child\',\'spouse\',\'sibling\',\'adoptive_parent\',\'adoptive_child\',\'step_parent\',\'step_child\',\'other\') NOT NULL,
            direction ENUM(\'a_to_b\',\'bidirectional\') NOT NULL DEFAULT \'a_to_b\',
            status ENUM(\'pending\',\'confirmed\',\'disputed\',\'rejected\',\'revoked\') NOT NULL DEFAULT \'pending\',
            source_type ENUM(\'user_input\',\'archive_ocr\',\'ai_extraction\',\'oral_history\',\'imported\') NOT NULL DEFAULT \'user_input\',
            source_id VARCHAR(190) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            submitted_by BIGINT UNSIGNED NOT NULL,
            reviewed_by BIGINT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rel_family_status (family_id, status),
            KEY idx_rel_a (person_a_id, relationship_type),
            KEY idx_rel_b (person_b_id, relationship_type),
            CONSTRAINT fk_rel_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_a FOREIGN KEY (person_a_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_b FOREIGN KEY (person_b_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_rel_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
