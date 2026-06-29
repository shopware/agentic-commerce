<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Shopware\Core\TestBootstrapper;

$shopwareProjectDir = getenv('SHOPWARE_PROJECT_DIR');

// When SHOPWARE_PROJECT_DIR is set (CI): load Shopware's vendor autoloader directly.
// The full TestBootstrapper->bootstrap() initialises the kernel and compiles the DI
// container, which is too heavy for unit tests and fails on CI test fixtures.
if (\is_string($shopwareProjectDir) && is_dir($shopwareProjectDir)) {
    $vendorAutoload = $shopwareProjectDir.'/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        /** @var Composer\Autoload\ClassLoader $classLoader */
        $classLoader = require $vendorAutoload;
        $classLoader->addPsr4('Swag\\AgenticCommerce\\', \dirname(__DIR__).'/src');
        $classLoader->addPsr4('Swag\\AgenticCommerce\\Tests\\', __DIR__);

        Swag\AgenticCommerce\Tests\Compat\FakeConnection::register();

        return $classLoader;
    }
}

// Fallback: full TestBootstrapper for local/monorepo development.
if (!class_exists(TestBootstrapper::class)) {
    $installed = (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('shopware/core'))
        ? InstalledVersions::getInstallPath('shopware/core')
        : null;
    if (\is_string($installed) && is_file($installed.'/TestBootstrapper.php')) {
        require_once $installed.'/TestBootstrapper.php';
    }
}

if (!class_exists(TestBootstrapper::class)) {
    $monorepo = \dirname(__DIR__, 4).'/src/Core/TestBootstrapper.php';
    if (is_file($monorepo)) {
        require_once $monorepo;
    }
}

if (!class_exists(TestBootstrapper::class)) {
    throw new RuntimeException('Could not locate Shopware TestBootstrapper.');
}

$corePath = (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('shopware/core'))
    ? InstalledVersions::getInstallPath('shopware/core')
    : null;
if (!\is_string($corePath) || !is_file($corePath.'/TestBootstrapper.php')) {
    $corePath = \dirname(__DIR__, 4).'/src/Core';
}
$projectDir = \dirname($corePath, str_ends_with($corePath, '/src/Core') ? 2 : 3);

// In test env the DI compile (AttributeEntityCompiler) reflects core's test-fixture
// entities under Shopware\Tests\*. Those namespaces are autoload-dev and are absent from a
// lane dumped with --no-dev, which crashes the kernel boot with a ReflectionException. Map
// them onto the active Composer loader (pointing at the lane's tests/ dirs) before booting.
$composerLoader = require $projectDir.'/vendor/autoload.php';
if ($composerLoader instanceof Composer\Autoload\ClassLoader) {
    $coreTestNamespaces = [
        'Shopware\\Tests\\Examples\\' => '/tests/examples/',
        'Shopware\\Tests\\Unit\\' => '/tests/unit/',
        'Shopware\\Tests\\Integration\\' => '/tests/integration/',
        'Shopware\\Tests\\Migration\\' => '/tests/migration/',
        'Shopware\\Tests\\DevOps\\' => '/tests/devops/',
    ];
    foreach ($coreTestNamespaces as $namespace => $relativePath) {
        if (is_dir($projectDir.$relativePath)) {
            $composerLoader->addPsr4($namespace, $projectDir.$relativePath);
        }
    }
    $composerLoader->register();
}

$classLoader = (new TestBootstrapper())
    ->setProjectDir($projectDir)
    ->setLoadEnvFile(true)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

$classLoader->addPsr4('Swag\\AgenticCommerce\\', \dirname(__DIR__).'/src');
$classLoader->addPsr4('Swag\\AgenticCommerce\\Tests\\', __DIR__);

Swag\AgenticCommerce\Tests\Compat\FakeConnection::register();

return $classLoader;
