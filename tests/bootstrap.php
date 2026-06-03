<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__.'/../.tools/vendor/autoload.php',
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../../vendor/autoload.php',
    __DIR__.'/../../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
    }
}

require_once __DIR__.'/phpstan/McpTool.php';

if ('1' === getenv('SWAG_AGENTIC_COMMERCE_SHOPWARE_BOOTSTRAP')) {
    require __DIR__.'/TestBootstrap.php';
}
