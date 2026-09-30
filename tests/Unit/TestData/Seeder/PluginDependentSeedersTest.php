<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Rule\CustomerLoggedInRule;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Seeder\BundleSeeder;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductPayloadBuilder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * @internal
 */
#[CoversClass(DynamicAccessSeeder::class)]
#[CoversClass(BundleSeeder::class)]
class PluginDependentSeedersTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    public function testDynamicAccessProductIsRestrictedToLoggedInCustomers(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([]);

        (new DynamicAccessSeeder($productRepository, $ruleRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment()))
            ->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        $ruleId = TestDataIds::id(DynamicAccessSeeder::RULE_CUSTOMER_LOGGED_IN);
        $condition = $ruleRepository->upserts[0][0]['conditions'][0]['children'][0]['children'][0];
        static::assertSame(CustomerLoggedInRule::RULE_NAME, $condition['type']);
        static::assertSame(['isLoggedIn' => true], $condition['value']);

        $product = $productRepository->creates[0][0];
        static::assertSame('SWAG-AC-TEST-MEMBERS-ONLY', $product['productNumber']);
        static::assertSame([['id' => $ruleId]], $product[TestDataEnvironment::DYNAMIC_ACCESS_PRODUCT_FIELD]);
        static::assertSame(TaxFixture::TAX_ID, $product['taxId']);
    }

    public function testDynamicAccessRemovalDeletesProductAndRule(): void
    {
        $productId = TestDataIds::id(DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT);
        $ruleId = TestDataIds::id(DynamicAccessSeeder::RULE_CUSTOMER_LOGGED_IN);
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[$productId]]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([[$ruleId]]);

        $seeder = new DynamicAccessSeeder($productRepository, $ruleRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment());

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $productId]]], $productRepository->deletes);
        static::assertSame([[['id' => $ruleId]]], $ruleRepository->deletes);
    }

    public function testBundleCombinesThePhysicalDefaultVariantAndTheCustomFieldsProductAtTenPercentOff(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);

        (new BundleSeeder($productRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment()))
            ->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        $bundle = $productRepository->creates[0][0];
        static::assertSame(TestDataEnvironment::BUNDLE_PRODUCT_TYPE, $bundle['type']);
        static::assertSame(
            [TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION), TestDataIds::id(ProductSeeder::CUSTOM_FIELDS)],
            array_column($bundle['bundleItems'], 'productId'),
        );
        static::assertSame(TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION.'.a5'), $bundle['bundleItems'][0]['defaultVariantId']);
        static::assertSame('percentage', $bundle['bundleDiscounts'][0]['type']);
        static::assertSame(10.0, $bundle['bundleDiscounts'][0]['value']);
        static::assertSame(ProductPayloadBuilder::visibilityPayloads([self::SALES_CHANNEL_ID]), $bundle['visibilities']);
    }

    public function testDynamicAccessExistsChecksTheProductAndRemovalReportsAbsence(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[TestDataIds::id(DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT)], [], []]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([[]]);
        $seeder = new DynamicAccessSeeder($productRepository, $ruleRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment());

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
        static::assertSame([], $productRepository->deletes);
    }

    public function testBundleUsesCommercialsDetailLayoutOnlyWhenItIsDefined(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);

        (new BundleSeeder($productRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment()))
            ->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        $bundle = $productRepository->creates[0][0];
        if (\defined(BundleSeeder::CMS_DETAIL_PAGE_CONSTANT)) {
            static::assertSame(\constant(BundleSeeder::CMS_DETAIL_PAGE_CONSTANT), $bundle['cmsPageId']);
        } else {
            static::assertArrayNotHasKey('cmsPageId', $bundle);
        }
    }

    public function testBundleExistsAndRemoveUseTheBundleProduct(): void
    {
        $bundleId = TestDataIds::id(BundleSeeder::BUNDLE_PRODUCT);
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[$bundleId], [$bundleId], [], []]);
        $seeder = new BundleSeeder($productRepository, (new ReferencesFixture())->productReferences, $this->inactiveEnvironment());

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $bundleId]]], $productRepository->deletes);
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testSkipReasonsComeFromTheEnvironment(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([]);
        $productReferences = (new ReferencesFixture())->productReferences;

        static::assertSame('SwagDynamicAccess is not installed or not active.', (new DynamicAccessSeeder($productRepository, $ruleRepository, $productReferences, $this->inactiveEnvironment()))->unavailableReason());
        static::assertSame('SwagCommercial is not installed or not active.', (new BundleSeeder($productRepository, $productReferences, $this->inactiveEnvironment()))->unavailableReason());
    }

    private function inactiveEnvironment(): TestDataEnvironment
    {
        return new TestDataEnvironment([], $this->createMock(DefinitionInstanceRegistry::class), null);
    }
}
