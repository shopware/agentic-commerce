<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$pluginRoot = \dirname(__DIR__);

// Autoloaders shipped by a full Shopware install ("lane"). The plugin lives in
// custom/plugins/<plugin> (or vendor/shopware/<plugin>), so the platform
// autoloader sits a few levels up.
$laneAutoloadCandidates = [
    __DIR__.'/../../../../vendor/autoload.php',
    __DIR__.'/../../../vendor/autoload.php',
];

$laneAutoload = null;
foreach ($laneAutoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $laneAutoload = $candidate;
        break;
    }
}

if (null !== $laneAutoload) {
    // Inside a full Shopware install the platform autoloader is the single source
    // of truth: it provides shopware/core, the framework's Symfony/Doctrine
    // versions, the UCP SDK, PHPUnit and the plugin's own src. Relying on it alone
    // keeps exactly one PHPUnit in the process. The plugin pins PHPUnit 10.5 (for
    // PHP 8.1 support) in .tools/vendor; loading that alongside a newer platform
    // PHPUnit (e.g. 11.x on 6.7) puts two incompatible majors in one process and
    // crashes. bin/run.php matches this by preferring the platform PHPUnit binary.
    $loader = require $laneAutoload;

    if ($loader instanceof ClassLoader) {
        // src is only registered on the platform autoloader when the plugin is
        // composer-required; tests live under autoload-dev and are never part of
        // the platform package, so register both explicitly. Re-registering an
        // already-mapped prefix is harmless.
        $loader->addPsr4('Swag\\AgenticCommerce\\', $pluginRoot.'/src');
        $loader->addPsr4('Swag\\AgenticCommerce\\Tests\\', __DIR__);
    }
} else {
    // Standalone / CI: no Shopware around. Use the plugin's own tooling autoloader
    // (PHPUnit 10.5 + plugin src + tests + UCP SDK).
    foreach ([$pluginRoot.'/.tools/vendor/autoload.php', $pluginRoot.'/vendor/autoload.php'] as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
        }
    }
}

require_once __DIR__.'/phpstan/McpTool.php';

if ('1' === getenv('SWAG_AGENTIC_COMMERCE_SHOPWARE_BOOTSTRAP')) {
    require __DIR__.'/TestBootstrap.php';
}
