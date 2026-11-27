<?php
declare(strict_types=1);

return [
    'version' => '20261127_013',
    'name' => 'create_archive_tags',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_archive_tag (family_id, name),
    CONSTRAINT fk_archive_tags_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
