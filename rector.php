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
        // Hand-crafted DI config with explicit cross-version service registrations.
        // ServiceSettersToSettersAutodiscoveryRector crashes on single-class namespace
        // groups produced after ServiceTagsToDefaultsAutoconfigureRector removes tags.
        __DIR__ . '/src/Resources/config/services.php',
    ])
    ->withPreparedSets(symfonyCodeQuality: true, symfonyConfigs: true);
