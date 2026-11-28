<?php
declare(strict_types=1);

/**
 * 原始文件与处理后文件的双哈希与处理溯源。
 * 原有 sha256 继续表示“当前可下载文件”的哈希，保持旧数据/旧测试语义；
 * original_* 记录用户实际上传的字节，processing_* 记录服务端处理结果。
 */
return [
    'version' => '20261128_009',
    'name' => 'archive_file_hashes',
    'sql' => [
        'ALTER TABLE archive_files
            ADD COLUMN original_sha256 CHAR(64) DEFAULT NULL,
            ADD COLUMN original_size BIGINT UNSIGNED DEFAULT NULL,
            ADD COLUMN original_storage_key VARCHAR(500) DEFAULT NULL,
            ADD COLUMN processed_sha256 CHAR(64) DEFAULT NULL,
            ADD COLUMN processing_method VARCHAR(50) NOT NULL DEFAULT \'none\',
            ADD COLUMN processing_library VARCHAR(100) DEFAULT NULL,
            ADD COLUMN processing_params TEXT DEFAULT NULL,
            ADD COLUMN processed_at DATETIME DEFAULT NULL,
            ADD COLUMN original_kept TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 1',
        'CREATE TABLE IF NOT EXISTS archive_file_processing (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            archive_file_id BIGINT UNSIGNED NOT NULL,
            original_sha256 CHAR(64) NOT NULL,
            processed_sha256 CHAR(64) NOT NULL,
            original_size BIGINT UNSIGNED NOT NULL,
            processed_size BIGINT UNSIGNED NOT NULL,
            processing_method VARCHAR(50) NOT NULL,
            processing_library VARCHAR(100) DEFAULT NULL,
            processing_params TEXT DEFAULT NULL,
            worker_id VARCHAR(100) DEFAULT NULL,
            verified TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_afp_file (archive_file_id),
            CONSTRAINT fk_afp_file FOREIGN KEY (archive_file_id) REFERENCES archive_files (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
