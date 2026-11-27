<?php
declare(strict_types=1);

return [
    'version' => '20261127_016',
    'name' => 'create_archive_versions',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    privacy VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL,
    change_note VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_archive_version (archive_id, version),
    CONSTRAINT fk_archive_versions_archive FOREIGN KEY (archive_id) REFERENCES archives (id) ON DELETE CASCADE,
    CONSTRAINT fk_archive_versions_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
