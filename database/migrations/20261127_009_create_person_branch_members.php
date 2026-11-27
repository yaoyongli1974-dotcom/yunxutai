<?php
declare(strict_types=1);

return [
    'version' => '20261127_009',
    'name' => 'create_person_branch_members',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS person_branch_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_person_branch (person_id, branch_id),
            KEY idx_branch_members (branch_id),
            CONSTRAINT fk_pbm_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_branch FOREIGN KEY (branch_id) REFERENCES family_branches (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
