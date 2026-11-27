<?php
declare(strict_types=1);

return [
    'version' => '20261127_010',
    'name' => 'create_person_merge_log',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS person_merge_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            primary_id BIGINT UNSIGNED NOT NULL,
            duplicate_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_merged_duplicate (duplicate_id),
            KEY idx_merge_primary (primary_id),
            CONSTRAINT fk_merge_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_merge_primary FOREIGN KEY (primary_id) REFERENCES family_persons (id) ON DELETE RESTRICT,
            CONSTRAINT fk_merge_duplicate FOREIGN KEY (duplicate_id) REFERENCES family_persons (id) ON DELETE RESTRICT,
            CONSTRAINT fk_merge_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
