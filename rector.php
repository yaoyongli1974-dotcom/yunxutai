<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/app', __DIR__ . '/bin'])
    ->withSkip([__DIR__ . '/app/Views'])
    ->withPhpSets(php84: true)
    ->withRules([]);
