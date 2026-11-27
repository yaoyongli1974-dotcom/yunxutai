<?php
declare(strict_types=1);

return [
    'version' => '20261127_012',
    'name' => 'create_archive_files',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_id BIGINT UNSIGNED NOT NULL,
    storage_key VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    file_role ENUM(\'original\',\'thumbnail\',\'restored\',\'preview\',\'transcript\',\'converted\') NOT NULL DEFAULT \'original\',
    processing_status ENUM(\'ready\',\'processing\',\'failed\') NOT NULL DEFAULT \'ready\',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_archive_storage_key (storage_key),
    KEY idx_archive_files (archive_id, file_role),
    CONSTRAINT fk_archive_files_archive FOREIGN KEY (archive_id) REFERENCES archives (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
