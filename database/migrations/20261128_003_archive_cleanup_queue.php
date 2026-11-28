<?php
declare(strict_types=1);

return [
    'version' => '20261128_003',
    'name' => 'archive_cleanup_queue',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_cleanup_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED DEFAULT NULL,
    storage_key VARCHAR(500) NOT NULL,
    status ENUM(\'pending\',\'processing\',\'done\',\'failed\') NOT NULL DEFAULT \'pending\',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cleanup_archive_key (archive_id, storage_key),
    KEY idx_cleanup_status (status, attempts),
    CONSTRAINT fk_cleanup_queue_archive FOREIGN KEY (archive_id) REFERENCES archives (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
