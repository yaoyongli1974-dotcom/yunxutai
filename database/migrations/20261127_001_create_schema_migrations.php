<?php
declare(strict_types=1);

return [
    'version' => '20261127_001',
    'name' => 'create_schema_migrations',
    'sql' => [
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            checksum CHAR(64) NOT NULL,
            executed_at DATETIME DEFAULT NULL,
            status ENUM(\'applied\',\'failed\') NOT NULL DEFAULT \'failed\',
            error TEXT DEFAULT NULL,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
];
