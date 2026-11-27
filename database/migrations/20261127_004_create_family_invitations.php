<?php
declare(strict_types=1);

return [
    'version' => '20261127_004',
    'name' => 'create_family_invitations',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS family_invitations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            role ENUM(\'admin\',\'genealogy_editor\',\'archive_editor\',\'member\',\'guest\') NOT NULL DEFAULT \'member\',
            token_hash CHAR(64) NOT NULL,
            status ENUM(\'pending\',\'accepted\',\'revoked\',\'expired\') NOT NULL DEFAULT \'pending\',
            expires_at DATETIME NOT NULL,
            invited_by BIGINT UNSIGNED NOT NULL,
            accepted_by BIGINT UNSIGNED DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_invite_token (token_hash),
            KEY idx_invite_family (family_id, status),
            CONSTRAINT fk_invitations_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_invitations_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_invitations_acceptor FOREIGN KEY (accepted_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
