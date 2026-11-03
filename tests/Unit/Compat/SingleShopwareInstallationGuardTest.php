<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Compat;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Tests\Compat\SingleShopwareInstallationGuard;
use Symfony\Component\Filesystem\Filesystem;

/** @internal */
final class SingleShopwareInstallationGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/single-shopware-installation-guard-'.bin2hex(random_bytes(4));
        foreach (['/checkout/vendor/composer', '/packagist/vendor/composer', '/plugin/.tools/vendor/composer', '/plugin/.tools/vendor/shopware'] as $directory) {
            mkdir($this->root.$directory, 0o777, true);
        }
        foreach ([
            '/checkout/src/Core/Kernel.php',
            '/checkout/src/Storefront/Storefront.php',
            '/checkout/src/Administration/Administration.php',
            '/other/src/Storefront/Storefront.php',
            '/packagist/vendor/shopware/core/Kernel.php',
        ] as $file) {
            mkdir(\dirname($this->root.$file), 0o777, true);
            touch($this->root.$file);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testThePluginTreeLinkedToTheCheckoutIsTheSameInstallation(): void
    {
        symlink($this->root.'/checkout/src/Core', $this->root.'/plugin/.tools/vendor/shopware/core');
        symlink($this->root.'/checkout/src/Storefront', $this->root.'/plugin/.tools/vendor/shopware/storefront');
        $checkout = $this->loader(['Shopware\\' => $this->root.'/checkout/vendor/composer/../../src/']);
        $pluginTree = $this->loader([
            'Shopware\\Core\\' => $this->root.'/plugin/.tools/vendor/composer/../shopware/core/',
            'Shopware\\Storefront\\' => $this->root.'/plugin/.tools/vendor/composer/../shopware/storefront/',
        ]);
        static::assertIsString($checkout->findFile('Shopware\\Core\\Kernel'));
        static::assertIsString($pluginTree->findFile('Shopware\\Core\\Kernel'));

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/checkout/vendor' => $checkout,
            $this->root.'/plugin/.tools/vendor' => $pluginTree,
        ]);
    }

    public function testLoadersWithoutShopwareNamespacesAreIgnored(): void
    {
        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/tools/vendor' => $this->loader(['PHPStan\\' => $this->root.'/tools/vendor/phpstan/src/']),
            $this->root.'/checkout/vendor' => $this->loader(['Shopware\\' => $this->root.'/checkout/src/']),
        ]);

        $this->addToAssertionCount(1);
    }

    public function testACheckoutNextToAnInstalledCoreIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(\sprintf(
            '#Shopware\\\\Core\\\\Kernel from %1$s/checkout/src/Core/Kernel\.php \(registered by .*/checkout/vendor\).*Shopware\\\\Core\\\\Kernel from %1$s/packagist/vendor/shopware/core/Kernel\.php \(registered by .*/packagist/vendor\)#s',
            preg_quote((string) realpath($this->root), '#'),
        ));

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/checkout/vendor' => $this->loader(['Shopware\\' => $this->root.'/checkout/src/']),
            $this->root.'/packagist/vendor' => $this->loader(['Shopware\\Core\\' => $this->root.'/packagist/vendor/shopware/core/']),
        ]);
    }

    public function testCoreAndStorefrontFromDifferentCheckoutsAreRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#Shopware\\\\Core\\\\Kernel from .*/checkout/src/Core/Kernel\.php.*Shopware\\\\Storefront\\\\Storefront from .*/other/src/Storefront/Storefront\.php#s');

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/checkout/vendor' => $this->loader(['Shopware\\Core\\' => $this->root.'/checkout/src/Core/']),
            $this->root.'/other/vendor' => $this->loader(['Shopware\\Storefront\\' => $this->root.'/other/src/Storefront/']),
        ]);
    }

    /**
     * @param array<string, string> $prefixes
     */
    private function loader(array $prefixes): ClassLoader
    {
        $loader = new ClassLoader();
        foreach ($prefixes as $prefix => $directory) {
            $loader->setPsr4($prefix, [$directory]);
        }

        return $loader;
    }
}
