<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Shopware\Core\TestBootstrapper;

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
    $shopwareProjectDir = getenv('SHOPWARE_PROJECT_DIR');
    if (\is_string($shopwareProjectDir) && is_file($shopwareProjectDir.'/src/Core/TestBootstrapper.php')) {
        require_once $shopwareProjectDir.'/src/Core/TestBootstrapper.php';
    }
}

if (!class_exists(TestBootstrapper::class)) {
    throw new RuntimeException('Could not locate Shopware TestBootstrapper.');
}

$shopwareProjectDir = getenv('SHOPWARE_PROJECT_DIR');
if (\is_string($shopwareProjectDir) && is_dir($shopwareProjectDir)) {
    $corePath = $shopwareProjectDir.'/src/Core';
    $projectDir = $shopwareProjectDir;
} else {
    $corePath = (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('shopware/core'))
        ? InstalledVersions::getInstallPath('shopware/core')
        : null;
    if (!\is_string($corePath) || !is_file($corePath.'/TestBootstrapper.php')) {
        $corePath = \dirname(__DIR__, 4).'/src/Core';
    }
    $projectDir = \dirname($corePath, str_ends_with($corePath, '/src/Core') ? 2 : 3);
}
$classLoader = (new TestBootstrapper())
    ->setProjectDir($projectDir)
    ->setLoadEnvFile(true)
    ->addCallingPlugin()
    ->bootstrap()
    ->getClassLoader();

$classLoader->addPsr4('Swag\\AgenticCommerce\\', \dirname(__DIR__).'/src');
$classLoader->addPsr4('Swag\\AgenticCommerce\\Tests\\', __DIR__);

return $classLoader;
