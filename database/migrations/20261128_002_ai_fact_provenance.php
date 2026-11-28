<?php
declare(strict_types=1);

return [
    'version' => '20261128_002',
    'name' => 'ai_fact_provenance',
    'sql' => [
        'ALTER TABLE memory_facts
            ADD COLUMN source_archive_id BIGINT UNSIGNED DEFAULT NULL,
            ADD COLUMN source_version_id BIGINT UNSIGNED DEFAULT NULL,
            ADD COLUMN source_excerpt TEXT DEFAULT NULL,
            ADD COLUMN subject_type VARCHAR(40) DEFAULT NULL,
            ADD COLUMN subject_id BIGINT UNSIGNED DEFAULT NULL,
            ADD KEY idx_memory_fact_subject (subject_type, subject_id),
            ADD KEY idx_memory_fact_source_archive (source_archive_id)',
    ],
];
