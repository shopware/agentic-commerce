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
        foreach (['Core', 'Storefront'] as $package) {
            mkdir($this->root.'/checkout/src/'.$package, 0o777, true);
            mkdir($this->root.'/other/src/'.$package, 0o777, true);
        }
        mkdir($this->root.'/checkout/vendor/composer', 0o777, true);
        mkdir($this->root.'/plugin/.tools/vendor/composer', 0o777, true);
        mkdir($this->root.'/plugin/.tools/vendor/shopware', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testATreeLinkedToTheCheckoutCountsAsTheSameInstallation(): void
    {
        symlink($this->root.'/checkout/src/Core', $this->root.'/plugin/.tools/vendor/shopware/core');
        symlink($this->root.'/checkout/src/Storefront', $this->root.'/plugin/.tools/vendor/shopware/storefront');
        static::assertIsString(realpath($this->root.'/checkout/vendor/composer/../../src/Core/'));
        static::assertSame(
            realpath($this->root.'/checkout/vendor/composer/../../src/Core/'),
            realpath($this->root.'/plugin/.tools/vendor/composer/../shopware/core/'),
        );

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/checkout/vendor' => $this->loader([
                'Shopware\\Core\\' => $this->root.'/checkout/vendor/composer/../../src/Core/',
                'Shopware\\Storefront\\' => $this->root.'/checkout/vendor/composer/../../src/Storefront/',
            ]),
            $this->root.'/plugin/.tools/vendor' => $this->loader([
                'Shopware\\Core\\' => $this->root.'/plugin/.tools/vendor/composer/../shopware/core/',
                'Shopware\\Storefront\\' => $this->root.'/plugin/.tools/vendor/composer/../shopware/storefront/',
            ]),
        ]);
    }

    public function testLoadersWithoutShopwareNamespacesAreIgnored(): void
    {
        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/tools/vendor' => $this->loader(['PHPStan\\' => $this->root.'/tools/vendor/phpstan/src/']),
            $this->root.'/checkout/vendor' => $this->loader(['Shopware\\Core\\' => $this->root.'/checkout/src/Core/']),
        ]);

        $this->addToAssertionCount(1);
    }

    public function testTwoCoreInstallationsAreRejectedWithBothSourcesNamed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(\sprintf(
            '#Shopware\\\\Core\\\\ from %1$s/other/src/Core \(registered by .*/other/vendor\).*Shopware\\\\Core\\\\ from %1$s/checkout/src/Core \(registered by .*/checkout/vendor\)#s',
            preg_quote((string) realpath($this->root), '#'),
        ));

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/other/vendor' => $this->loader(['Shopware\\Core\\' => $this->root.'/other/src/Core/']),
            $this->root.'/checkout/vendor' => $this->loader(['Shopware\\Core\\' => $this->root.'/checkout/src/Core/']),
        ]);
    }

    public function testStorefrontFromAnotherInstallationThanCoreIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#Shopware\\\\Storefront\\\\ from .*/checkout/src/Storefront.*Shopware\\\\Storefront\\\\ from .*/other/src/Storefront#s');

        SingleShopwareInstallationGuard::assertSingleInstallation([
            $this->root.'/checkout/vendor' => $this->loader([
                'Shopware\\Core\\' => $this->root.'/checkout/src/Core/',
                'Shopware\\Storefront\\' => $this->root.'/checkout/src/Storefront/',
            ]),
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
