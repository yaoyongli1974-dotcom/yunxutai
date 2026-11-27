<?php
declare(strict_types=1);

return [
    'version' => '20261127_015',
    'name' => 'create_archive_person_links',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS archive_person_links (
    archive_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    relation_type VARCHAR(40) NOT NULL DEFAULT \'subject\',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (archive_id, person_id),
    KEY idx_archive_person_person (person_id),
    CONSTRAINT fk_apl_archive FOREIGN KEY (archive_id) REFERENCES archives (id) ON DELETE CASCADE,
    CONSTRAINT fk_apl_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,
    CONSTRAINT fk_apl_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
