<?php
declare(strict_types=1);

$institutions = 'CREATE TABLE IF NOT EXISTS institutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(60) NOT NULL,
    config_json JSON NULL,
    status ENUM("active","disabled") NOT NULL DEFAULT "active",
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institutions_slug (slug),
    CONSTRAINT fk_institutions_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$members = 'CREATE TABLE IF NOT EXISTS institution_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM("owner","admin","member") NOT NULL DEFAULT "member",
    status ENUM("active","removed") NOT NULL DEFAULT "active",
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_member (institution_id, user_id),
    KEY idx_institution_members_user (user_id, status),
    CONSTRAINT fk_institution_members_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$families = 'CREATE TABLE IF NOT EXISTS family_institutions (
    family_id BIGINT UNSIGNED NOT NULL,
    institution_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (family_id),
    KEY idx_family_institutions_institution (institution_id),
    CONSTRAINT fk_family_institutions_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
    CONSTRAINT fk_family_institutions_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'version' => '20261127_026',
    'name' => 'create_institutions',
    'sql' => [$institutions, $members, $families],
];
