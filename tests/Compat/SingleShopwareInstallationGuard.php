<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Compat;

use Composer\Autoload\ClassLoader;

/**
 * Stops a PHPStan or PHPUnit process in which two Composer trees map a Shopware namespace to
 * different directories. PHP would then build one class hierarchy out of two Shopware versions,
 * a parent from one tree and its child from the other.
 *
 * @see docs/local-development.md
 *
 * @internal
 */
final class SingleShopwareInstallationGuard
{
    /**
     * Storefront and administration require shopware/core "*", so Composer alone would accept them
     * from another installation than core.
     */
    private const NAMESPACES = [
        'Shopware\\Core\\',
        'Shopware\\Storefront\\',
        'Shopware\\Administration\\',
    ];

    public static function assertRegisteredLoaders(): void
    {
        self::assertSingleInstallation(ClassLoader::getRegisteredLoaders());
    }

    /**
     * @param array<string, ClassLoader> $loaders keyed by vendor directory
     */
    public static function assertSingleInstallation(array $loaders): void
    {
        $conflicts = [];
        foreach (self::NAMESPACES as $namespace) {
            $vendorDirsByDirectory = [];
            foreach ($loaders as $vendorDir => $loader) {
                foreach ($loader->getPrefixesPsr4()[$namespace] ?? [] as $directory) {
                    $realDirectory = realpath($directory);
                    if (false !== $realDirectory) {
                        $vendorDirsByDirectory[$realDirectory][] = $vendorDir;
                    }
                }
            }

            if (\count($vendorDirsByDirectory) <= 1) {
                continue;
            }

            foreach ($vendorDirsByDirectory as $directory => $vendorDirs) {
                $conflicts[] = \sprintf('%s from %s (registered by %s)', $namespace, $directory, implode(', ', array_unique($vendorDirs)));
            }
        }

        if ([] === $conflicts) {
            return;
        }

        throw new \RuntimeException(\sprintf("Shopware is autoloaded from more than one installation:\n  - %s\nLoad a single tree: link the checkout into the plugin with bin/ci-link-shopware.sh, or run inside the Shopware project.", implode("\n  - ", $conflicts)));
    }
}
