<?php
declare(strict_types=1);

return [
    'version' => '20261127_003',
    'name' => 'create_family_members',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS family_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM(\'owner\',\'admin\',\'genealogy_editor\',\'archive_editor\',\'member\',\'guest\') NOT NULL DEFAULT \'member\',
            status ENUM(\'invited\',\'active\',\'left\',\'banned\') NOT NULL DEFAULT \'invited\',
            joined_at DATETIME DEFAULT NULL,
            invited_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_family_user (family_id, user_id),
            KEY idx_family_member_user (user_id, status),
            CONSTRAINT fk_family_members_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_family_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_family_members_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
