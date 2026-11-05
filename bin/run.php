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
    $vendorDir = loadedVendorDir($pluginDir, $coreDir);
    $withCoreConfig = includesShopwarePhpstanConfig($vendorDir, $coreDir);
    $tmpDir = $pluginDir.'/var/cache/phpstan';

    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0o775, true) && !is_dir($tmpDir)) {
        fwrite(\STDERR, \sprintf("Failed to create PHPStan tmp dir '%s'.\n", $tmpDir));
        exit(1);
    }

    $rendered = strtr($template, [
        '__SHOPWARE_CORE_DIR__' => $coreDir,
        '__SHOPWARE_PHPSTAN_INCLUDES__' => renderShopwarePhpstanIncludes($coreDir, $withCoreConfig),
        '__FUTURE_COMPATIBILITY_INCLUDE__' => renderFutureCompatibilityInclude($pluginDir),
        '__SHOPWARE_PHPSTAN_PARAMETERS__' => renderShopwarePhpstanParameters($coreDir, $withCoreConfig),
        '__SHOPWARE_UNEXPECTED_TEST_COVERS_IGNORE__' => renderUnexpectedTestCoversIgnore($coreDir, $withCoreConfig),
        '__PHPSTAN_TMP_DIR__' => $tmpDir,
    ]);

    file_put_contents($pluginDir.'/phpstan.neon', $rendered);

    return renderPhpstanAutoload($vendorDir, $tmpDir);
}

// Exactly one tree: the plugin's own when it exists, the Shopware project's otherwise.
function loadedVendorDir(string $pluginDir, string $coreDir): string
{
    return is_file($pluginDir.'/.tools/vendor/autoload.php')
        ? $pluginDir.'/.tools/vendor'
        : \dirname($coreDir, 2).'/vendor';
}

function locateShopwareCore(string $pluginDir): string
{
    // PHPStan always loads the plugin's own tree when it exists, so its core is the one analysed.
    $toolsCoreDir = realpath($pluginDir.'/.tools/vendor/shopware/core');
    if (false !== $toolsCoreDir) {
        assertLaneMatchesToolsCore($toolsCoreDir);

        return $toolsCoreDir;
    }

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

function assertLaneMatchesToolsCore(string $toolsCoreDir): void
{
    $lanes = [
        'SHOPWARE_CORE_DIR' => [''],
        'SHOPWARE_PROJECT_DIR' => ['/src/Core', '/vendor/shopware/core'],
    ];

    foreach ($lanes as $variable => $suffixes) {
        $dir = getenv($variable);
        if (!\is_string($dir) || '' === $dir) {
            continue;
        }

        foreach ($suffixes as $suffix) {
            $laneCoreDir = realpath($dir.$suffix);
            if (false === $laneCoreDir || !is_file($laneCoreDir.'/Kernel.php') || $laneCoreDir === $toolsCoreDir) {
                continue;
            }

            // bin/ci-link-shopware.sh takes a source checkout's root, never a core directory.
            $recovery = str_ends_with($laneCoreDir, '/src/Core')
                ? \sprintf('Link the lane first: bin/ci-link-shopware.sh %s && composer update', \dirname($laneCoreDir, 2))
                : 'An installed shopware/core cannot be linked into .tools; unset the variable or run inside that project.';

            fwrite(\STDERR, \sprintf(
                "%s points at shopware/core %s, but the plugin's .tools/vendor holds %s.\n"
                ."Analysing one against the other loads two Shopware versions into one process.\n"
                ."%s\n",
                $variable,
                $laneCoreDir,
                $toolsCoreDir,
                $recovery,
            ));
            exit(1);
        }
    }
}

function renderShopwarePhpstanIncludes(string $coreDir, bool $withCoreConfig): string
{
    $phpStanDir = $coreDir.'/DevOps/StaticAnalyze/PHPStan';
    if (!$withCoreConfig) {
        return '';
    }

    if (is_file($phpStanDir.'/common.neon')) {
        return '    - '.$phpStanDir.'/common.neon';
    }

    return implode("\n", [
        '    - '.$phpStanDir.'/extension.neon',
        '    - '.$phpStanDir.'/rules.neon',
    ]);
}

function renderFutureCompatibilityInclude(string $pluginDir): string
{
    $rules = $pluginDir.'/.tools/vendor/shopwarelabs/phpstan-shopware/future-compatibility.neon';

    return is_file($rules) ? '    - '.$rules : '';
}

function renderShopwarePhpstanParameters(string $coreDir, bool $withCoreConfig): string
{
    if (!$withCoreConfig || !supportsConfigurableCoversRule($coreDir)) {
        return '';
    }

    return implode("\n", [
        '    shopware:',
        '        allowedUnitTestClassNamespaces:',
        '            - Swag\AgenticCommerce\Tests\Unit\\',
        '            - Swag\AgenticCommerce\Tests\Integration\Migration\\',
    ]);
}

function renderUnexpectedTestCoversIgnore(string $coreDir, bool $withCoreConfig): string
{
    if ($withCoreConfig && supportsConfigurableCoversRule($coreDir)) {
        return '';
    }

    return implode("\n", [
        '        # CoversClass on unit tests in subdirs — fixed by configurable covers namespaces in newer Shopware.',
        '        -',
        '            identifier: shopware.unexpectedTestCovers',
        "            message: '#.+#'",
    ]);
}

// Core's config includes its own vendor/ by relative path and registers that tree's Symplify rules,
// so it only works when that vendor/ is the tree PHPStan loads.
function includesShopwarePhpstanConfig(string $vendorDir, string $coreDir): bool
{
    return realpath($vendorDir) === realpath(\dirname($coreDir, 2).'/vendor')
        && is_file($vendorDir.'/phpstan/phpstan/conf/bleedingEdge.neon');
}

function supportsConfigurableCoversRule(string $coreDir): bool
{
    $commonConfig = $coreDir.'/DevOps/StaticAnalyze/PHPStan/common.neon';
    if (!is_file($commonConfig)) {
        return false;
    }

    $contents = file_get_contents($commonConfig);

    return \is_string($contents) && str_contains($contents, 'allowedUnitTestClassNamespaces: list(string)');
}

function renderPhpstanAutoload(string $vendorDir, string $tmpDir): string
{
    $autoloadPath = $tmpDir.'/autoload.php';
    $treeAutoload = $vendorDir.'/autoload.php';

    $rendered = \sprintf(
        "<?php\n\nif (is_file(%s)) {\n    require_once %s;\n}\n",
        var_export($treeAutoload, true),
        var_export($treeAutoload, true),
    );

    file_put_contents($autoloadPath, $rendered);

    return $autoloadPath;
}
