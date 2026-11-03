<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Compat;

use Composer\Autoload\ClassLoader;

/**
 * Stops a PHPStan or PHPUnit process that autoloads Shopware from more than one installation.
 * PHP would then build one class hierarchy out of two Shopware versions, a parent from one tree
 * and its child from the other.
 *
 * @see docs/local-development.md
 *
 * @internal
 */
final class SingleShopwareInstallationGuard
{
    /**
     * One class at the root of each package. Storefront and administration require shopware/core
     * "*", so Composer alone would accept them from another installation than core.
     */
    private const PACKAGE_CLASSES = [
        'Shopware\\Core\\Kernel',
        'Shopware\\Storefront\\Storefront',
        'Shopware\\Administration\\Administration',
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
        $sourcesByInstallation = [];
        foreach ($loaders as $vendorDir => $loader) {
            foreach (self::PACKAGE_CLASSES as $class) {
                // Composer's own lookup: exact and parent prefixes (a checkout's `Shopware\` => src/) and classmaps.
                $file = $loader->findFile($class);
                $realFile = \is_string($file) ? realpath($file) : false;
                if (false !== $realFile) {
                    // <installation>/<package>/<Class>.php: src/ of a checkout, vendor/shopware/ of an install.
                    $sourcesByInstallation[\dirname($realFile, 2)][] = \sprintf('%s from %s (registered by %s)', $class, $realFile, $vendorDir);
                }
            }
        }

        if (\count($sourcesByInstallation) <= 1) {
            return;
        }

        throw new \RuntimeException(\sprintf("Shopware is autoloaded from more than one installation:\n  - %s\nLoad a single tree: link the checkout into the plugin with bin/ci-link-shopware.sh, or run inside the Shopware project.", implode("\n  - ", array_merge(...array_values($sourcesByInstallation)))));
    }
}
