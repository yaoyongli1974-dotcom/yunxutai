<?php
declare(strict_types=1);

return [
    'version' => '20261128_004',
    'name' => 'audit_request_id',
    'sql' => [
        'ALTER TABLE audit_logs
            ADD COLUMN request_id CHAR(32) DEFAULT NULL,
            ADD KEY idx_audit_request_id (request_id)',
    ],
];
