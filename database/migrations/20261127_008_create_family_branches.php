<?php
declare(strict_types=1);

return [
    'version' => '20261127_008',
    'name' => 'create_family_branches',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS family_branches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            parent_branch_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch_family (family_id, parent_branch_id),
            CONSTRAINT fk_branch_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_branch_parent FOREIGN KEY (parent_branch_id) REFERENCES family_branches (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
