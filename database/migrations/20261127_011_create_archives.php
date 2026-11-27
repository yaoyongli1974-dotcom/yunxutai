<?php
declare(strict_types=1);

return [
    'version' => '20261127_011',
    'name' => 'create_archives',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archives (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    archive_type VARCHAR(40) NOT NULL DEFAULT \'document\',
    event_date DATE NULL,
    location VARCHAR(180) NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT \'user_input\',
    source_description TEXT NULL,
    privacy ENUM(\'private\',\'family\',\'public\') NOT NULL DEFAULT \'private\',
    status ENUM(\'active\',\'archived\',\'delete_requested\',\'deleted\') NOT NULL DEFAULT \'active\',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_archive_family (family_id, status, id),
    KEY idx_archive_event (event_date),
    CONSTRAINT fk_archives_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_archives_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
