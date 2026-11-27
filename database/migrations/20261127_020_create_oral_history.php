<?php
declare(strict_types=1);

return [
    'version' => '20261127_020',
    'name' => 'create_oral_history',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS oral_history_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    family_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    topic VARCHAR(120) DEFAULT NULL,
    interviewer VARCHAR(120) DEFAULT NULL,
    interviewee_person_id BIGINT UNSIGNED DEFAULT NULL,
    consent_status ENUM(\'none\',\'granted\',\'revoked\') NOT NULL DEFAULT \'none\',
    consent_at DATETIME DEFAULT NULL,
    consent_by BIGINT UNSIGNED DEFAULT NULL,
    audio_file_id BIGINT UNSIGNED DEFAULT NULL,
    processing_status ENUM(\'draft\',\'queued\',\'processing\',\'completed\',\'failed\') NOT NULL DEFAULT \'draft\',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oral_session_family (family_id, created_at),
    CONSTRAINT fk_oral_session_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_oral_session_person FOREIGN KEY (interviewee_person_id) REFERENCES family_persons (id) ON DELETE SET NULL,
    CONSTRAINT fk_oral_session_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS oral_history_segments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    speaker VARCHAR(120) DEFAULT NULL,
    start_time INT UNSIGNED NOT NULL DEFAULT 0,
    end_time INT UNSIGNED NOT NULL DEFAULT 0,
    transcript TEXT NULL,
    corrected_transcript TEXT NULL,
    review_status ENUM(\'pending\',\'confirmed\',\'rejected\') NOT NULL DEFAULT \'pending\',
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oral_segment_session (session_id, start_time),
    CONSTRAINT fk_oral_segment_session FOREIGN KEY (session_id) REFERENCES oral_history_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_oral_segment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
];
