<?php
declare(strict_types=1);

return [
    'version' => '20261127_025',
    'name' => 'create_archive_photo_tags',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_photo_tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    archive_id BIGINT UNSIGNED NOT NULL,
    archive_file_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    x DECIMAL(5,2) NOT NULL,
    y DECIMAL(5,2) NOT NULL,
    width DECIMAL(5,2) NOT NULL,
    height DECIMAL(5,2) NOT NULL,
    tagged_by BIGINT UNSIGNED NOT NULL,
    review_status ENUM(\'pending\',\'confirmed\',\'rejected\') NOT NULL DEFAULT \'pending\',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_photo_tags_file (archive_file_id),
    KEY idx_photo_tags_family (family_id, review_status),
    CONSTRAINT fk_photo_tags_file FOREIGN KEY (archive_file_id) REFERENCES archive_files (id) ON DELETE CASCADE,
    CONSTRAINT fk_photo_tags_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE RESTRICT,
    CONSTRAINT fk_photo_tags_tagger FOREIGN KEY (tagged_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
