<?php
declare(strict_types=1);

return [
    'version' => '20261128_010',
    'name' => 'order_idempotency',
    'sql' => [
        'ALTER TABLE ai_orders
            ADD COLUMN idempotency_key CHAR(64) DEFAULT NULL,
            ADD UNIQUE KEY uq_ai_orders_idem (idempotency_key)',
    ],
];
