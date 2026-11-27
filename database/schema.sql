-- 云叙台 · 网络纪念平台
-- MySQL 8.4 LTS / MariaDB 10.11+ compatible, utf8mb4
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(32) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    totp_secret VARCHAR(64) DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME DEFAULT NULL,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 小程序 / 移动端 Bearer Token；仅保存 sha256 散列，明文只在签发时返回一次
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(20) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    openid VARCHAR(64) NOT NULL,
    unionid VARCHAR(64) DEFAULT NULL,
    provider VARCHAR(20) NOT NULL DEFAULT 'miniapp',
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wx_identities_openid (openid),
    KEY idx_wx_identities_user (user_id),
    CONSTRAINT fk_wx_identities_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_settings (
    name VARCHAR(80) NOT NULL,
    value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deceased (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    gender ENUM('male','female','unknown') NOT NULL DEFAULT 'unknown',
    birth_date DATE DEFAULT NULL,
    death_date DATE DEFAULT NULL,
    birth_place VARCHAR(190) DEFAULT NULL,
    death_place VARCHAR(190) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    cover_media_id BIGINT UNSIGNED DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deceased_name (full_name),
    CONSTRAINT fk_deceased_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    deceased_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(190) NOT NULL,
    summary VARCHAR(500) DEFAULT NULL,
    visibility ENUM('public','unlisted','private') NOT NULL DEFAULT 'unlisted',
    status ENUM('draft','active','suspended') NOT NULL DEFAULT 'draft',
    allow_search_index TINYINT(1) NOT NULL DEFAULT 0,
    allow_guestbook TINYINT(1) NOT NULL DEFAULT 1,
    moderate_messages TINYINT(1) NOT NULL DEFAULT 1,
    theme VARCHAR(40) NOT NULL DEFAULT 'ink',
    created_by BIGINT UNSIGNED NOT NULL,
    published_at DATETIME DEFAULT NULL,
    suspended_reason VARCHAR(500) DEFAULT NULL,
    suspended_by BIGINT UNSIGNED DEFAULT NULL,
    declaration_accepted_at DATETIME DEFAULT NULL,
    declaration_version VARCHAR(20) DEFAULT NULL,
    verification_status ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
    verification_method VARCHAR(40) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    verified_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_memorials_slug (slug),
    KEY idx_memorials_visibility (visibility, status),
    KEY idx_memorials_creator (created_by),
    CONSTRAINT fk_memorials_deceased FOREIGN KEY (deceased_id) REFERENCES deceased (id) ON DELETE RESTRICT,
    CONSTRAINT fk_memorials_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_memorials_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorial_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    method VARCHAR(40) NOT NULL DEFAULT 'death_certificate',
    statement TEXT NOT NULL,
    document_path VARCHAR(255) DEFAULT NULL,
    document_name VARCHAR(190) DEFAULT NULL,
    document_mime VARCHAR(100) DEFAULT NULL,
    document_size INT UNSIGNED DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewer_id BIGINT UNSIGNED DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_verifications_status (status, created_at),
    KEY idx_verifications_memorial (memorial_id, created_at),
    CONSTRAINT fk_verifications_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_verifications_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_verifications_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memorial_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','editor','viewer') NOT NULL DEFAULT 'viewer',
    status ENUM('invited','active','revoked') NOT NULL DEFAULT 'active',
    invited_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_memorial_member (memorial_id, user_id),
    KEY idx_member_user (user_id, status),
    CONSTRAINT fk_member_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) DEFAULT NULL,
    role ENUM('editor','viewer') NOT NULL DEFAULT 'viewer',
    token CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    accepted_by BIGINT UNSIGNED DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitations_token (token),
    KEY idx_invitations_memorial (memorial_id),
    CONSTRAINT fk_invitations_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_invitations_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS life_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    event_date DATE DEFAULT NULL,
    year_label VARCHAR(40) DEFAULT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT DEFAULT NULL,
    location VARCHAR(190) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_life_memorial (memorial_id, sort_order, event_date),
    CONSTRAINT fk_life_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    author_name VARCHAR(80) DEFAULT NULL,
    status ENUM('draft','pending','published','rejected','hidden') NOT NULL DEFAULT 'draft',
    review_note VARCHAR(500) DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_articles_memorial (memorial_id, status, published_at),
    CONSTRAINT fk_articles_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    uploader_id BIGINT UNSIGNED DEFAULT NULL,
    kind ENUM('photo','video','audio','document') NOT NULL DEFAULT 'photo',
    disk_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    caption VARCHAR(255) DEFAULT NULL,
    visibility ENUM('visitors','members') NOT NULL DEFAULT 'visitors',
    status ENUM('active','hidden') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_media_memorial (memorial_id, status, kind),
    CONSTRAINT fk_media_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI 自助服务：价格由运营后台维护，当前不接入支付，仅作展示与任务成本记录
CREATE TABLE IF NOT EXISTS ai_services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT '次',
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    price_note VARCHAR(190) DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ai_services_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    service_slug VARCHAR(40) NOT NULL,
    status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    input_json JSON DEFAULT NULL,
    output_json JSON DEFAULT NULL,
    media_id BIGINT UNSIGNED DEFAULT NULL,
    article_id BIGINT UNSIGNED DEFAULT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'demo',
    cost_cents INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_jobs_memorial (memorial_id, created_at),
    KEY idx_ai_jobs_status (status, created_at),
    KEY idx_ai_jobs_user (user_id, created_at),
    CONSTRAINT fk_ai_jobs_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_jobs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_jobs_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_jobs_article FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 祭奠行为与纪念馆资料分开保存，便于后续生成纪念时间轴/年度记录
CREATE TABLE IF NOT EXISTS ai_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    service_slug VARCHAR(40) NOT NULL,
    service_name VARCHAR(80) NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    input_json JSON DEFAULT NULL,
    status ENUM('pending','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
    task_id BIGINT UNSIGNED DEFAULT NULL,
    payment_ref VARCHAR(190) DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    reviewed_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_orders_status (status, created_at),
    KEY idx_ai_orders_user (user_id, created_at),
    KEY idx_ai_orders_memorial (memorial_id, created_at),
    CONSTRAINT fk_ai_orders_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_orders_task FOREIGN KEY (task_id) REFERENCES ai_jobs (id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_orders_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tributes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    type ENUM('flower','candle','incense','bow') NOT NULL,
    note VARCHAR(200) DEFAULT NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
    ip_hash CHAR(64) DEFAULT NULL,
    status ENUM('visible','pending','hidden') NOT NULL DEFAULT 'visible',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    audited_by BIGINT UNSIGNED DEFAULT NULL,
    audited_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_tributes_memorial (memorial_id, status, created_at),
    KEY idx_tributes_user (user_id, created_at),
    CONSTRAINT fk_tributes_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_tributes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    author_name VARCHAR(80) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('pending','visible','hidden') NOT NULL DEFAULT 'pending',
    ip_hash CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    audited_by BIGINT UNSIGNED DEFAULT NULL,
    audited_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_messages_memorial (memorial_id, status, created_at),
    CONSTRAINT fk_messages_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_type ENUM('memorial','message','tribute','media','article') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    memorial_id BIGINT UNSIGNED DEFAULT NULL,
    reporter_user_id BIGINT UNSIGNED DEFAULT NULL,
    reporter_name VARCHAR(80) DEFAULT NULL,
    contact VARCHAR(190) DEFAULT NULL,
    reason ENUM('fake_person','living_person','privacy','insult','illegal','copyright','other') NOT NULL,
    priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    detail TEXT DEFAULT NULL,
    status ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
    handled_by BIGINT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    resolution VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_status (status, priority, created_at),
    KEY idx_reports_memorial (memorial_id),
    CONSTRAINT fk_reports_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    memorial_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    relationship VARCHAR(120) NOT NULL,
    statement TEXT NOT NULL,
    evidence_note TEXT DEFAULT NULL,
    status ENUM('open','approved','rejected') NOT NULL DEFAULT 'open',
    handled_by BIGINT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    resolution VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_claims_status (status, created_at),
    CONSTRAINT fk_claims_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE CASCADE,
    CONSTRAINT fk_claims_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    memorial_id BIGINT UNSIGNED DEFAULT NULL,
    name VARCHAR(80) NOT NULL,
    contact VARCHAR(190) NOT NULL,
    service_type ENUM('photo_restore','memorial_video','life_archive','family_archive','institution','other') NOT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
    handled_by BIGINT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_service_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    memorial_id BIGINT UNSIGNED DEFAULT NULL,
    type ENUM('export','delete','revoke') NOT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('open','processing','done','rejected') NOT NULL DEFAULT 'open',
    handled_by BIGINT UNSIGNED DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    resolution VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_data_requests_status (status, created_at),
    CONSTRAINT fk_data_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) DEFAULT NULL,
    target_id BIGINT UNSIGNED DEFAULT NULL,
    meta JSON DEFAULT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS throttle (
    throttle_key VARCHAR(190) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at DATETIME NOT NULL,
    PRIMARY KEY (throttle_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 云叙台 2.0 新增表（与 database/migrations/20261127_*.php 完全一致，禁止单边修改）
-- ============================================================

-- 20261127_001 create_schema_migrations
CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            checksum CHAR(64) NOT NULL,
            executed_at DATETIME DEFAULT NULL,
            status ENUM('applied','failed') NOT NULL DEFAULT 'failed',
            error TEXT DEFAULT NULL,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_002 create_families
CREATE TABLE IF NOT EXISTS families (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            surname VARCHAR(50) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            avatar_file_id BIGINT UNSIGNED DEFAULT NULL,
            origin_place VARCHAR(190) DEFAULT NULL,
            privacy ENUM('private','unlisted','public') NOT NULL DEFAULT 'private',
            owner_user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_family_slug (slug),
            KEY idx_family_owner (owner_user_id),
            KEY idx_family_status (status),
            CONSTRAINT fk_families_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_003 create_family_members
CREATE TABLE IF NOT EXISTS family_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role ENUM('owner','admin','genealogy_editor','archive_editor','member','guest') NOT NULL DEFAULT 'member',
            status ENUM('invited','active','left','banned') NOT NULL DEFAULT 'invited',
            joined_at DATETIME DEFAULT NULL,
            invited_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_family_user (family_id, user_id),
            KEY idx_family_member_user (user_id, status),
            CONSTRAINT fk_family_members_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_family_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_family_members_inviter FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_004 create_family_invitations
CREATE TABLE IF NOT EXISTS family_invitations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            role ENUM('admin','genealogy_editor','archive_editor','member','guest') NOT NULL DEFAULT 'member',
            token_hash CHAR(64) NOT NULL,
            status ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_005 create_family_persons
CREATE TABLE IF NOT EXISTS family_persons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            gender ENUM('unknown','male','female','other') NOT NULL DEFAULT 'unknown',
            birth_date DATE DEFAULT NULL,
            death_date DATE DEFAULT NULL,
            birth_place VARCHAR(190) DEFAULT NULL,
            death_place VARCHAR(190) DEFAULT NULL,
            biography TEXT DEFAULT NULL,
            avatar_file_id BIGINT UNSIGNED DEFAULT NULL,
            privacy ENUM('private','family','public') NOT NULL DEFAULT 'private',
            verification_status ENUM('none','pending','verified','disputed') NOT NULL DEFAULT 'none',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_006 create_person_memorial_links
CREATE TABLE IF NOT EXISTS person_memorial_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            relation_note VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_person_memorial (person_id, memorial_id),
            KEY idx_memorial_person (memorial_id),
            KEY idx_links_family (family_id),
            CONSTRAINT fk_links_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_links_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_links_memorial FOREIGN KEY (memorial_id) REFERENCES memorials (id) ON DELETE RESTRICT,
            CONSTRAINT fk_links_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_007_create_person_relationships
CREATE TABLE IF NOT EXISTS person_relationships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_a_id BIGINT UNSIGNED NOT NULL,
            person_b_id BIGINT UNSIGNED NOT NULL,
            relationship_type ENUM('parent','child','spouse','sibling','adoptive_parent','adoptive_child','step_parent','step_child','other') NOT NULL,
            direction ENUM('a_to_b','bidirectional') NOT NULL DEFAULT 'a_to_b',
            status ENUM('pending','confirmed','disputed','rejected','revoked') NOT NULL DEFAULT 'pending',
            source_type ENUM('user_input','archive_ocr','ai_extraction','oral_history','imported') NOT NULL DEFAULT 'user_input',
            source_id VARCHAR(190) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            submitted_by BIGINT UNSIGNED NOT NULL,
            reviewed_by BIGINT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_rel_family_status (family_id, status),
            KEY idx_rel_a (person_a_id, relationship_type),
            KEY idx_rel_b (person_b_id, relationship_type),
            CONSTRAINT fk_rel_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_a FOREIGN KEY (person_a_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_b FOREIGN KEY (person_b_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_rel_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_rel_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_008_create_family_branches
CREATE TABLE IF NOT EXISTS family_branches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            parent_branch_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_branch_family (family_id, parent_branch_id),
            CONSTRAINT fk_branch_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_branch_parent FOREIGN KEY (parent_branch_id) REFERENCES family_branches (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_009_create_person_branch_members
CREATE TABLE IF NOT EXISTS person_branch_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_person_branch (person_id, branch_id),
            KEY idx_branch_members (branch_id),
            CONSTRAINT fk_pbm_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_person FOREIGN KEY (person_id) REFERENCES family_persons (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_branch FOREIGN KEY (branch_id) REFERENCES family_branches (id) ON DELETE CASCADE,
            CONSTRAINT fk_pbm_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20261127_010_create_person_merge_log
CREATE TABLE IF NOT EXISTS person_merge_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_id BIGINT UNSIGNED NOT NULL,
            primary_id BIGINT UNSIGNED NOT NULL,
            duplicate_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_merged_duplicate (duplicate_id),
            KEY idx_merge_primary (primary_id),
            CONSTRAINT fk_merge_family FOREIGN KEY (family_id) REFERENCES families (id) ON DELETE CASCADE,
            CONSTRAINT fk_merge_primary FOREIGN KEY (primary_id) REFERENCES family_persons (id) ON DELETE RESTRICT,
            CONSTRAINT fk_merge_duplicate FOREIGN KEY (duplicate_id) REFERENCES family_persons (id) ON DELETE RESTRICT,
            CONSTRAINT fk_merge_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
