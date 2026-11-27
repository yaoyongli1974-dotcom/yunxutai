<?php
declare(strict_types=1);

return [
    'version' => '20261127_006',
    'name' => 'create_person_memorial_links',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS person_memorial_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            relation_note VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            status ENUM(\'active\',\'revoked\') NOT NULL DEFAULT \'active\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_person_memorial (person_id, memorial_id),
            KEY idx_memorial_person (memorial_id),
            KEY idx_links_family (family_id),
            CONSTRAINT fk_links_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_links_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_links_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE RESTRICT,
            CONSTRAINT fk_links_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
