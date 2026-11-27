<?php
declare(strict_types=1);

return [
    'version' => '20261127_022',
    'name' => 'create_family_events',
    'sql' => [
        "CREATE TABLE IF NOT EXISTS family_events (\n    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n    family_id BIGINT UNSIGNED NOT NULL,\n    title VARCHAR(180) NOT NULL,\n    description TEXT NULL,\n    event_date DATE NOT NULL,\n    end_date DATE DEFAULT NULL,\n    location VARCHAR(180) DEFAULT NULL,\n    event_type VARCHAR(40) NOT NULL DEFAULT 'other',\n    privacy ENUM('private','family','public') NOT NULL DEFAULT 'family',\n    source_type ENUM('user_input','archive','oral_history','person_profile','imported') NOT NULL DEFAULT 'user_input',\n    source_id BIGINT UNSIGNED DEFAULT NULL,\n    status ENUM('active','archived') NOT NULL DEFAULT 'active',\n    created_by BIGINT UNSIGNED NOT NULL,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n    PRIMARY KEY (id),\n    KEY idx_family_event_time (family_id, event_date),\n    KEY idx_family_event_status (status, privacy),\n    CONSTRAINT fk_family_event_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,\n    CONSTRAINT fk_family_event_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS family_event_persons (\n    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n    event_id BIGINT UNSIGNED NOT NULL,\n    person_id BIGINT UNSIGNED NOT NULL,\n    created_by BIGINT UNSIGNED NOT NULL,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n    PRIMARY KEY (id),\n    UNIQUE KEY uq_family_event_person (event_id, person_id),\n    CONSTRAINT fk_event_person_event FOREIGN KEY (event_id) REFERENCES family_events (id) ON DELETE CASCADE,\n    CONSTRAINT fk_event_person_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,\n    CONSTRAINT fk_event_person_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],
];
