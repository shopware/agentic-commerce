<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductTypeRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityWriteGateway;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;
use Swag\AgenticCommerce\Tests\Unit\TestData\Fixtures\BundleItemDefinition;
use Swag\AgenticCommerce\Tests\Unit\TestData\Fixtures\DynamicAccessProductDefinition;
use Swag\AgenticCommerce\Tests\Unit\TestData\Fixtures\PlainProductDefinition;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(TestDataEnvironment::class)]
class TestDataEnvironmentTest extends TestCase
{
    private const ALL_ACTIVE = [
        TestDataEnvironment::PAYPAL_PLUGIN => [],
        TestDataEnvironment::COMMERCIAL_PLUGIN => [],
        TestDataEnvironment::DYNAMIC_ACCESS_PLUGIN => [],
    ];

    public function testEveryGroupIsAvailableWhenThePluginsAreActiveAndCapable(): void
    {
        $environment = new TestDataEnvironment(self::ALL_ACTIVE, $this->registry(true, true), $this->productTypeRegistry(true));

        static::assertNull($environment->payPalUnavailableReason());
        static::assertNull($environment->dynamicAccessUnavailableReason());
        static::assertNull($environment->bundleUnavailableReason());
    }

    public function testInactivePluginsAreReportedByName(): void
    {
        $environment = new TestDataEnvironment([], $this->registry(true, true), null);

        static::assertSame('SwagPayPal is not installed or not active.', $environment->payPalUnavailableReason());
        static::assertSame('SwagDynamicAccess is not installed or not active.', $environment->dynamicAccessUnavailableReason());
        static::assertSame('SwagCommercial is not installed or not active.', $environment->bundleUnavailableReason());
    }

    public function testDynamicAccessWithoutTheProductRuleFieldIsUnavailable(): void
    {
        $environment = new TestDataEnvironment(self::ALL_ACTIVE, $this->registry(false, true), null);

        static::assertSame('This SwagDynamicAccess version does not restrict products by rule.', $environment->dynamicAccessUnavailableReason());
    }

    public function testBundlesNeedTheProductTypeRegistryAndTheBundleEntity(): void
    {
        $withoutRegistry = new TestDataEnvironment(self::ALL_ACTIVE, $this->registry(true, true), null);
        $withoutEntity = new TestDataEnvironment(self::ALL_ACTIVE, $this->registry(true, false), $this->productTypeRegistry(true));

        $expected = 'Product bundles need SwagCommercial with product bundles on Shopware 6.7.14 or newer.';
        static::assertSame($expected, $withoutRegistry->bundleUnavailableReason());
        static::assertSame($expected, $withoutEntity->bundleUnavailableReason());
    }

    public function testBundlesAreUnavailableWhenTheLicenceDidNotRegisterTheBundleType(): void
    {
        $environment = new TestDataEnvironment(self::ALL_ACTIVE, $this->registry(true, true), $this->productTypeRegistry(false));

        static::assertSame('The Shopware Commercial licence does not include product bundles (PRODUCT_BUNDLE).', $environment->bundleUnavailableReason());
    }

    private function registry(bool $hasDynamicAccessField, bool $hasBundleEntity): DefinitionInstanceRegistry
    {
        $definitions = [$hasDynamicAccessField ? DynamicAccessProductDefinition::class : PlainProductDefinition::class];
        if ($hasBundleEntity) {
            $definitions[] = BundleItemDefinition::class;
        }

        return new StaticDefinitionInstanceRegistry(
            $definitions,
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );
    }

    private function productTypeRegistry(bool $hasBundleType): ProductTypeRegistry
    {
        if (!class_exists(ProductTypeRegistry::class)) {
            static::markTestSkipped('ProductTypeRegistry ships with Shopware 6.7.7.');
        }

        return new ProductTypeRegistry($hasBundleType ? ['physical', 'digital', TestDataEnvironment::BUNDLE_PRODUCT_TYPE] : ['physical', 'digital']);
    }
}
