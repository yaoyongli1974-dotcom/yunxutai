<?php
declare(strict_types=1);

return [
    'version' => '20261128_005',
    'name' => 'performance_indexes',
    'sql' => [
        'ALTER TABLE family_persons
            ADD INDEX idx_person_family_deleted_id (family_id, deleted_at, id)',
        'ALTER TABLE person_relationships
            ADD INDEX idx_rel_family_id (family_id, id)',
    ],
];
