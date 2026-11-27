<?php
declare(strict_types=1);

return [
    'version' => '20261127_005',
    'name' => 'create_family_persons',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS family_persons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            gender ENUM(\'unknown\',\'male\',\'female\',\'other\') NOT NULL DEFAULT \'unknown\',
            birth_date DATE DEFAULT NULL,
            death_date DATE DEFAULT NULL,
            birth_place VARCHAR(190) DEFAULT NULL,
            death_place VARCHAR(190) DEFAULT NULL,
            biography TEXT DEFAULT NULL,
            avatar_file_id BIGINT UNSIGNED DEFAULT NULL,
            privacy ENUM(\'private\',\'family\',\'public\') NOT NULL DEFAULT \'private\',
            verification_status ENUM(\'none\',\'pending\',\'verified\',\'disputed\') NOT NULL DEFAULT \'none\',
            is_deceased TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_person_family_name (family_id, name),
            KEY idx_person_dates (birth_date, death_date),
            CONSTRAINT fk_persons_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_persons_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
