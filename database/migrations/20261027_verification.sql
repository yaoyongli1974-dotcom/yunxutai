-- 2026-10-27 · 身份核验与举报优先级
-- 适用于已经运行过的数据库。推荐使用 `php bin/migrate.php`（幂等，可重复执行）。
-- 本 SQL 仅作参考：中途失败后重跑可能因列已存在而报错，生产请优先用 PHP 迁移脚本。

ALTER TABLE memorials
    ADD COLUMN verification_status ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none' AFTER declaration_version,
    ADD COLUMN verification_method VARCHAR(40) DEFAULT NULL AFTER verification_status,
    ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER verification_method,
    ADD COLUMN verified_by BIGINT UNSIGNED DEFAULT NULL AFTER verified_at,
    ADD CONSTRAINT fk_memorials_verified_by FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE reports
    ADD COLUMN priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER reason,
    ADD INDEX idx_reports_priority (status, priority, created_at);

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

-- 迁移前已存在的纪念馆：默认回退私密，待补充材料核验后再公开。
-- 如已人工确认某批历史纪念馆可信，可执行：php bin/migrate.php --grandfather
UPDATE memorials
SET visibility = 'private',
    allow_search_index = 0
WHERE verification_status = 'none'
  AND visibility IN ('public', 'unlisted');

-- 历史举报按原因补齐优先级
UPDATE reports
SET priority = CASE
    WHEN reason IN ('living_person', 'fake_person') THEN 'urgent'
    WHEN reason IN ('privacy', 'insult', 'illegal') THEN 'high'
    ELSE 'normal'
END
WHERE priority = 'normal';
