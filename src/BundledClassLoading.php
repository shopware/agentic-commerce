<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Composer\Autoload\ClassLoader;
use Shopware\Core\Framework\Log\Package;

/**
 * Registers the plugin's class loading and, where the release archive bundles the SDK, makes the bundled
 * copies win over the plugin and SDK copies in the shop's root `vendor/`.
 *
 * Core registers the PSR-4 entries from the `plugin.autoload` column, which `plugin:install -r`, `plugin:update -r`
 * and `plugin:update:all` refresh only after the process has booted. Must not use any other plugin class.
 *
 * @internal
 */
#[Package('framework')]
final class BundledClassLoading
{
    public const BUNDLED_SDK_DIRECTORY = 'lib/ucp-php-sdk';

    public const CLASS_LIST_FILE = '/lib/preload-classes.php';

    /**
     * Package directory in `vendor/`, mapped to the directory in the plugin that holds the same files.
     */
    private const PLUGIN_DIRECTORY_BY_VENDOR_PACKAGE = [
        'shopware/agentic-commerce' => '',
        'ucp-php-sdk/core' => self::BUNDLED_SDK_DIRECTORY.'/core',
        'ucp-php-sdk/symfony-bundle' => self::BUNDLED_SDK_DIRECTORY.'/symfony-bundle',
    ];

    private static bool $isRegistered = false;

    public static function register(): void
    {
        if (self::$isRegistered) {
            return;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if (false === $loader->findFile('Shopware\\Core\\Framework\\Plugin')) {
                continue;
            }

            self::$isRegistered = true;
            self::registerOn($loader, $vendorDir, \dirname(__DIR__));

            return;
        }
    }

    public static function registerOn(ClassLoader $loader, string $vendorDir, string $pluginRoot): void
    {
        $manifest = self::readManifest($pluginRoot);
        $autoloadSection = \is_array($manifest['autoload'] ?? null) ? $manifest['autoload'] : [];
        $relativeDirectoriesByPrefix = \is_array($autoloadSection['psr-4'] ?? null) ? $autoloadSection['psr-4'] : [];

        // A plugin inside `vendor/` is the Composer copy itself, so there is nothing to win over.
        $isBundledSdkPreferred = is_dir($pluginRoot.'/'.self::BUNDLED_SDK_DIRECTORY)
            && !str_starts_with($pluginRoot.'/', $vendorDir.'/');

        foreach ($relativeDirectoriesByPrefix as $prefix => $relativeDirectories) {
            $registeredDirectories = array_map(self::resolveDirectory(...), $loader->getPrefixesPsr4()[$prefix] ?? []);
            $bundledSdkDirectories = [];

            foreach ((array) $relativeDirectories as $relativeDirectory) {
                $directory = self::resolveDirectory($pluginRoot.'/'.$relativeDirectory);

                if ($isBundledSdkPreferred && str_starts_with($relativeDirectory, self::BUNDLED_SDK_DIRECTORY.'/')) {
                    $bundledSdkDirectories[] = $directory;
                } elseif (!\in_array($directory, $registeredDirectories, true)) {
                    $loader->addPsr4($prefix, $directory);
                }
            }

            if ([] !== $bundledSdkDirectories
                && \array_slice($registeredDirectories, 0, \count($bundledSdkDirectories)) !== $bundledSdkDirectories) {
                $loader->addPsr4($prefix, $bundledSdkDirectories, true);
            }
        }

        if ($isBundledSdkPreferred) {
            self::takeOverVendorCopies($loader, $vendorDir, $pluginRoot);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure the manifest can change between two calls
     */
    public static function readManifest(string $directory): array
    {
        $manifestFile = $directory.'/composer.json';
        $manifestJson = is_file($manifestFile) ? @file_get_contents($manifestFile) : false;

        if (false === $manifestJson) {
            return [];
        }

        try {
            $manifest = json_decode($manifestJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($manifest) ? $manifest : [];
    }

    /**
     * @return array{plugin: list<class-string>, sdk: list<class-string>}|null null in a development checkout
     */
    public static function readClassLists(string $pluginRoot): ?array
    {
        $file = $pluginRoot.self::CLASS_LIST_FILE;

        if (!is_file($file)) {
            return null;
        }

        try {
            $classLists = include $file;
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($classLists) || !\is_array($classLists['plugin'] ?? null) || !\is_array($classLists['sdk'] ?? null)) {
            return null;
        }

        /** @var array{plugin: list<class-string>, sdk: list<class-string>} $validClassLists */
        $validClassLists = $classLists;

        return $validClassLists;
    }

    /**
     * A shop that required the plugin through Composer, or the SDK next to it, has class map entries for these
     * classes that beat any PSR-4 entry. Looks up the classes of the archive instead of walking the class map,
     * which holds every class of the shop.
     */
    private static function takeOverVendorCopies(ClassLoader $loader, string $vendorDir, string $pluginRoot): void
    {
        $pluginDirectoryByCopyDirectory = [];

        foreach (self::PLUGIN_DIRECTORY_BY_VENDOR_PACKAGE as $vendorPackage => $pluginDirectory) {
            $copyDirectory = $vendorDir.'/'.$vendorPackage.'/';

            if (is_dir($copyDirectory)) {
                $pluginDirectoryByCopyDirectory[$copyDirectory] = $pluginDirectory;
            }
        }

        $classLists = [] === $pluginDirectoryByCopyDirectory ? null : self::readClassLists($pluginRoot);

        if (null === $classLists) {
            return;
        }

        $fileByClassName = $loader->getClassMap();
        $pluginFileByClassName = [];

        foreach ($classLists as $classNames) {
            foreach ($classNames as $className) {
                $file = $fileByClassName[$className] ?? null;

                if (!\is_string($file)) {
                    continue;
                }

                foreach ($pluginDirectoryByCopyDirectory as $copyDirectory => $pluginDirectory) {
                    if (!str_starts_with($file, $copyDirectory)) {
                        continue;
                    }

                    $pluginFile = rtrim($pluginRoot.'/'.$pluginDirectory, '/').'/'.substr($file, \strlen($copyDirectory));

                    if (is_file($pluginFile)) {
                        $pluginFileByClassName[$className] = $pluginFile;
                    }

                    break;
                }
            }
        }

        $loader->addClassMap($pluginFileByClassName);
    }

    private static function resolveDirectory(string $directory): string
    {
        $realDirectory = realpath($directory);

        return rtrim(false === $realDirectory ? $directory : $realDirectory, '/');
    }
}
