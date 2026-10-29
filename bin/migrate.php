#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 幂等迁移：身份核验、举报优先级、AI 任务表、小程序 API token。
 * 用法：php bin/migrate.php [--grandfather]
 *
 * 全新安装请直接使用 database/schema.sql（bin/install.php 已包含）。
 * 本脚本用于已运行过的旧库，可重复执行；只有首次新增 verification_status 列时
 * 才会自动把历史纪念馆标记为已核验，避免上线后突然不可见。
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Db;
use App\Models\AiService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅限命令行运行\n");
    exit(1);
}

$options = getopt('', ['grandfather']);
$forceGrandfather = isset($options['grandfather']);
$database = (string) config('db.database');

function columnExists(string $database, string $table, string $column): bool
{
    return (int) Db::value(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        [$database, $table, $column]
    ) > 0;
}

function indexExists(string $database, string $table, string $index): bool
{
    return (int) Db::value(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?',
        [$database, $table, $index]
    ) > 0;
}

function indexColumns(string $database, string $table, string $index): string
{
    return (string) Db::value(
        "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',')
         FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?",
        [$database, $table, $index]
    );
}

function constraintExists(string $database, string $table, string $constraint): bool
{
    return (int) Db::value(
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ?',
        [$database, $table, $constraint]
    ) > 0;
}

echo "静思园 · 数据库迁移\n数据库：{$database}\n\n";

try {
    $wasVerificationColumnMissing = !columnExists($database, 'memorials', 'verification_status');

    if ($wasVerificationColumnMissing) {
        Db::query(
            "ALTER TABLE memorials
             ADD COLUMN verification_status ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none' AFTER declaration_version,
             ADD COLUMN verification_method VARCHAR(40) DEFAULT NULL AFTER verification_status,
             ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER verification_method,
             ADD COLUMN verified_by BIGINT UNSIGNED DEFAULT NULL AFTER verified_at"
        );
        echo "✓ memorials 新增核验字段\n";
    } else {
        echo "· memorials 核验字段已存在\n";
    }

    if (!constraintExists($database, 'memorials', 'fk_memorials_verified_by')) {
        Db::query(
            'ALTER TABLE memorials
             ADD CONSTRAINT fk_memorials_verified_by FOREIGN KEY (verified_by)
             REFERENCES users (id) ON DELETE SET NULL'
        );
        echo "✓ memorials.verified_by 外键已创建\n";
    } else {
        echo "· memorials.verified_by 外键已存在\n";
    }

    if (!columnExists($database, 'reports', 'priority')) {
        Db::query(
            "ALTER TABLE reports
             ADD COLUMN priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER reason"
        );
        echo "✓ reports 新增 priority\n";
    } else {
        echo "· reports.priority 已存在\n";
    }

    if (!indexExists($database, 'reports', 'idx_reports_priority')) {
        $covered = indexColumns($database, 'reports', 'idx_reports_status');
        if ($covered !== 'status,priority,created_at') {
            Db::query('ALTER TABLE reports ADD INDEX idx_reports_priority (status, priority, created_at)');
            echo "✓ reports 举报优先级索引已创建\n";
        } else {
            echo "· reports 举报优先级已被 idx_reports_status 覆盖\n";
        }
    } else {
        echo "· reports 举报优先级索引已存在\n";
    }

    Db::query(
        "CREATE TABLE IF NOT EXISTS memorial_verifications (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "✓ memorial_verifications 表已就绪\n";

    Db::query(
        "CREATE TABLE IF NOT EXISTS ai_services (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    Db::query(
        "CREATE TABLE IF NOT EXISTS ai_jobs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    AiService::ensureDefaults();
    echo "✓ AI 服务与任务表已就绪\n";

    Db::query(
        "CREATE TABLE IF NOT EXISTS api_tokens (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "✓ api_tokens 表已就绪\n";

    if ($wasVerificationColumnMissing || $forceGrandfather) {
        if ($forceGrandfather) {
            $affected = Db::query(
                "UPDATE memorials
                 SET verification_status = 'verified',
                     verification_method = 'grandfathered',
                     verified_at = COALESCE(published_at, created_at)
                 WHERE verification_status = 'none'"
            )->rowCount();
            echo "⚠ 已按 --grandfather 将 {$affected} 个历史纪念馆标记为已核验；请确认已人工抽查。\n";
        } else {
            // 默认安全策略：历史公开/不公开馆先回退私密，待馆主补充材料并人工核验后再公开。
            $affected = Db::query(
                "UPDATE memorials
                 SET visibility = 'private', allow_search_index = 0
                 WHERE verification_status = 'none' AND visibility IN ('public','unlisted')"
            )->rowCount();
            echo "✓ 历史公开/不公开纪念馆已回退私密：{$affected} 个（待补充材料核验）\n";
        }
    }

    $priorities = Db::query(
        "UPDATE reports
         SET priority = CASE
             WHEN reason IN ('living_person','fake_person') THEN 'urgent'
             WHEN reason IN ('privacy','insult','illegal') THEN 'high'
             ELSE 'normal'
         END
         WHERE priority = 'normal' AND reason IN ('living_person','fake_person','privacy','insult','illegal')"
    )->rowCount();
    if ($priorities > 0) {
        echo "✓ 历史举报补全优先级：{$priorities} 条\n";
    }

    echo "\n迁移完成。\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '迁移失败：' . $e->getMessage() . "\n");
    exit(1);
}
