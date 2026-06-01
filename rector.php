<?php declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/src/Resources/app',
        __DIR__ . '/src/Resources/public',
    ])
    ->withPreparedSets(symfonyCodeQuality: true, symfonyConfigs: true);
