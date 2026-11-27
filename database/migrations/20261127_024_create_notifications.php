<?php
declare(strict_types=1);

return [
    'version' => '20261127_024',
    'name' => 'create_notifications',
    'sql' => [
        "CREATE TABLE IF NOT EXISTS notifications (\n    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n    user_id BIGINT UNSIGNED NOT NULL,\n    family_id BIGINT UNSIGNED DEFAULT NULL,\n    type VARCHAR(60) NOT NULL,\n    title VARCHAR(180) NOT NULL,\n    body TEXT NULL,\n    link VARCHAR(255) DEFAULT NULL,\n    channel ENUM('in_app','email') NOT NULL DEFAULT 'in_app',\n    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',\n    read_at DATETIME DEFAULT NULL,\n    error_message VARCHAR(255) DEFAULT NULL,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n    PRIMARY KEY (id),\n    KEY idx_notification_user (user_id, read_at, created_at),\n    KEY idx_notification_family (family_id, type),\n    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,\n    CONSTRAINT fk_notification_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE SET NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_preferences (\n    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n    user_id BIGINT UNSIGNED NOT NULL,\n    type VARCHAR(60) NOT NULL,\n    in_app TINYINT(1) NOT NULL DEFAULT 1,\n    email TINYINT(1) NOT NULL DEFAULT 0,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n    PRIMARY KEY (id),\n    UNIQUE KEY uq_notification_pref (user_id, type),\n    CONSTRAINT fk_notification_pref_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],
];
