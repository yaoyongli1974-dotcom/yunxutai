<?php
declare(strict_types=1);

return [
    'version' => '20261127_002',
    'name' => 'create_families',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS families (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            surname VARCHAR(50) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            avatar_file_id BIGINT UNSIGNED DEFAULT NULL,
            origin_place VARCHAR(190) DEFAULT NULL,
            privacy ENUM(\'private\',\'unlisted\',\'public\') NOT NULL DEFAULT \'private\',
            owner_user_id BIGINT UNSIGNED NOT NULL,
            status ENUM(\'active\',\'suspended\',\'archived\') NOT NULL DEFAULT \'active\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_family_slug (slug),
            KEY idx_family_owner (owner_user_id),
            KEY idx_family_status (status),
            CONSTRAINT fk_families_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
