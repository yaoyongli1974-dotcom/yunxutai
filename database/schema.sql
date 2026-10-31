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
