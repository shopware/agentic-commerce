<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(\STDERR, "Usage: php bin/run.php <binary> [...args]\n");
    exit(2);
}

$binary = $argv[1];
$args = \array_slice($argv, 2);
$pluginDir = \dirname(__DIR__);

$binaryPath = resolveBinary($pluginDir, $binary);
if (null === $binaryPath) {
    fwrite(\STDERR, \sprintf("Unable to resolve binary '%s'. Run composer install or use a Shopware lane.\n", $binary));
    exit(1);
}

if ('phpstan' === $binary) {
    $autoloadFile = renderPhpstanConfig($pluginDir);
    if (!\in_array('--autoload-file', $args, true) && !\in_array('-a', $args, true)) {
        $autoloadArgs = ['--autoload-file', $autoloadFile];
        if (isset($args[0]) && !str_starts_with($args[0], '-')) {
            array_splice($args, 1, 0, $autoloadArgs);
        } else {
            array_splice($args, 0, 0, $autoloadArgs);
        }
    }
}

$command = array_merge([$binaryPath], $args);
$escaped = implode(' ', array_map('escapeshellarg', $command));
passthru($escaped, $exitCode);

exit($exitCode);

function resolveBinary(string $pluginDir, string $binary): ?string
{
    // Inside a full Shopware install ("lane"), prefer the platform's PHPUnit so the
    // running binary matches the single autoloader tests/bootstrap.php loads there.
    // The plugin pins PHPUnit 10.5 (PHP 8.1) in .tools/vendor; on newer platforms
    // (e.g. 6.7 ships PHPUnit 11.x) running .tools alongside the platform autoloader
    // crashes, so defer to the platform binary. These roots mirror the lane
    // autoload candidates in tests/bootstrap.php.
    if ('phpunit' === $binary) {
        foreach ([\dirname($pluginDir, 3), \dirname($pluginDir, 2)] as $laneRoot) {
            $laneBinary = $laneRoot.'/vendor/bin/'.$binary;
            if (is_dir($laneRoot) && is_file($laneBinary)) {
                return $laneBinary;
            }
        }
    }

    $pluginTooling = $pluginDir.'/.tools/vendor/bin/'.$binary;
    if (is_file($pluginTooling)) {
        return $pluginTooling;
    }

    $shopwareProjectDir = getenv('SHOPWARE_PROJECT_DIR');
    if (
        'phpstan' === $binary
        && \is_string($shopwareProjectDir)
        && '' !== $shopwareProjectDir
    ) {
        $shopwarePhpstan = $shopwareProjectDir.'/vendor/bin/'.$binary;
        if (is_file($shopwarePhpstan)) {
            return $shopwarePhpstan;
        }
    }

    $roots = [
        $pluginDir,
        \dirname($pluginDir, 4),
        \dirname($pluginDir, 3),
    ];

    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }

        $tooling = $root.'/.tools/vendor/bin/'.$binary;
        if (is_file($tooling)) {
            return $tooling;
        }

        $direct = $root.'/vendor/bin/'.$binary;
        if (is_file($direct)) {
            return $direct;
        }

        foreach (glob($root.'/vendor-bin/*/vendor/bin/'.$binary) ?: [] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

function renderPhpstanConfig(string $pluginDir): string
{
    $templatePath = $pluginDir.'/phpstan.neon.dist';
    $template = file_get_contents($templatePath);
    if (false === $template) {
        fwrite(\STDERR, "phpstan.neon.dist not found.\n");
        exit(1);
    }

    $coreDir = locateShopwareCore($pluginDir);
    $tmpDir = $pluginDir.'/var/cache/phpstan';

    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0o775, true) && !is_dir($tmpDir)) {
        fwrite(\STDERR, \sprintf("Failed to create PHPStan tmp dir '%s'.\n", $tmpDir));
        exit(1);
    }

    $rendered = strtr($template, [
        '__SHOPWARE_CORE_DIR__' => $coreDir,
        '__SHOPWARE_PHPSTAN_INCLUDES__' => renderShopwarePhpstanIncludes($coreDir),
        '__PHPSTAN_TMP_DIR__' => $tmpDir,
    ]);

    file_put_contents($pluginDir.'/phpstan.neon', $rendered);

    return renderPhpstanAutoload($pluginDir, $coreDir, $tmpDir);
}

function locateShopwareCore(string $pluginDir): string
{
    $envCoreDir = getenv('SHOPWARE_CORE_DIR');
    if (\is_string($envCoreDir) && '' !== $envCoreDir && is_dir($envCoreDir.'/DevOps')) {
        return (string) realpath($envCoreDir);
    }

    $envProjectDir = getenv('SHOPWARE_PROJECT_DIR');
    if (\is_string($envProjectDir) && '' !== $envProjectDir && is_dir($envProjectDir.'/src/Core/DevOps')) {
        return (string) realpath($envProjectDir.'/src/Core');
    }

    $projectsDir = \dirname($pluginDir);
    $candidates = [
        $projectsDir.'/shopware/src/Core',
        $projectsDir.'/shopware-trunk/src/Core',
        $projectsDir.'/shopware-6-6-branch/src/Core',
        $projectsDir.'/shopware-6-5-branch/src/Core',
        $pluginDir.'/vendor/shopware/core',
        \dirname($pluginDir, 3).'/src/Core',
        \dirname($pluginDir, 4).'/src/Core',
        \dirname($pluginDir, 3).'/vendor/shopware/core',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate.'/DevOps')) {
            return (string) realpath($candidate);
        }
    }

    fwrite(\STDERR, "Unable to locate shopware/core for PHPStan. Set SHOPWARE_CORE_DIR or SHOPWARE_PROJECT_DIR.\n");
    exit(1);
}

function renderShopwarePhpstanIncludes(string $coreDir): string
{
    $phpStanDir = $coreDir.'/DevOps/StaticAnalyze/PHPStan';
    $shopwareProjectDir = \dirname($coreDir, 2);

    if (!is_file($shopwareProjectDir.'/vendor/phpstan/phpstan/conf/bleedingEdge.neon')) {
        return '';
    }

    if (is_file($phpStanDir.'/common.neon')) {
        return implode("\n", [
            '    - '.$phpStanDir.'/common.neon',
            '    - '.$phpStanDir.'/core-rules.neon',
        ]);
    }

    return implode("\n", [
        '    - '.$phpStanDir.'/extension.neon',
        '    - '.$phpStanDir.'/rules.neon',
        '    - '.$phpStanDir.'/core-rules.neon',
    ]);
}

function renderPhpstanAutoload(string $pluginDir, string $coreDir, string $tmpDir): string
{
    $autoloadPath = $tmpDir.'/autoload.php';
    $shopwareProjectDir = \dirname($coreDir, 2);
    $content = <<<'PHP'
        <?php

        $autoloadCandidates = [
            '__PLUGIN_DIR__/.tools/vendor/autoload.php',
            '__PLUGIN_DIR__/vendor/autoload.php',
            '__SHOPWARE_PROJECT_DIR__/vendor/autoload.php',
        ];

        foreach ($autoloadCandidates as $candidate) {
            if (is_file($candidate)) {
                require_once $candidate;
            }
        }
        PHP;

    $rendered = strtr($content, [
        '__PLUGIN_DIR__' => $pluginDir,
        '__SHOPWARE_PROJECT_DIR__' => $shopwareProjectDir,
    ]);

    file_put_contents($autoloadPath, $rendered);

    return $autoloadPath;
}
