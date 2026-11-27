<?php
declare(strict_types=1);

return [
    'version' => '20261127_014',
    'name' => 'create_archive_tag_links',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_tag_links (
    archive_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (archive_id, tag_id),
    KEY idx_tag_links_tag (tag_id),
    CONSTRAINT fk_tag_links_archive FOREIGN KEY (archive_id) REFERENCES archives (id) ON DELETE CASCADE,
    CONSTRAINT fk_tag_links_tag FOREIGN KEY (tag_id) REFERENCES archive_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
