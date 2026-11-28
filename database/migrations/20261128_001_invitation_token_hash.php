<?php
declare(strict_types=1);

return [
    'version' => '20261128_001',
    'name' => 'invitation_token_hash',
    'sql' => [
        'ALTER TABLE invitations
            ADD COLUMN token_hash CHAR(64) DEFAULT NULL,
            ADD UNIQUE KEY uq_invitations_token_hash (token_hash)',
    ],
];
