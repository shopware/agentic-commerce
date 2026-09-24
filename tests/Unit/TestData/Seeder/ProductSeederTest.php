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
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\TestDataTax;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * @internal
 */
#[CoversClass(ProductSeeder::class)]
#[CoversClass(TestDataTax::class)]
class ProductSeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    public function testPhysicalProductHasPhysicalVariantsAndOneDigitalVariant(): void
    {
        $products = $this->createProducts();

        $parent = $products['SWAG-AC-TEST-PHYSICAL'];
        static::assertSame('physical', $parent['type']);
        static::assertArrayNotHasKey('downloads', $parent);
        static::assertSame(
            [TestDataIds::id('format.printed-a5'), TestDataIds::id('format.printed-a4'), TestDataIds::id('format.pdf')],
            array_column($parent['configuratorSettings'], 'optionId'),
        );

        $variants = self::byProductNumber($parent['children']);
        static::assertSame('physical', $variants['SWAG-AC-TEST-PHYSICAL-A5']['type']);
        static::assertSame('physical', $variants['SWAG-AC-TEST-PHYSICAL-A4']['type']);
        static::assertArrayNotHasKey('downloads', $variants['SWAG-AC-TEST-PHYSICAL-A4']);

        $pdf = $variants['SWAG-AC-TEST-PHYSICAL-PDF'];
        static::assertSame('digital', $pdf['type']);
        static::assertSame(1, $pdf['maxPurchase']);
        static::assertSame(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), $pdf['downloads'][0]['mediaId']);
        static::assertSame([['id' => TestDataIds::id('format.pdf')]], $pdf['options']);
    }

    public function testDigitalProductHasDigitalVariantsAndOnePhysicalVariant(): void
    {
        $products = $this->createProducts();

        $parent = $products['SWAG-AC-TEST-DIGITAL'];
        static::assertSame('digital', $parent['type']);
        static::assertSame(TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), $parent['downloads'][0]['mediaId']);

        $variants = self::byProductNumber($parent['children']);
        foreach (['SWAG-AC-TEST-DIGITAL-MP3', 'SWAG-AC-TEST-DIGITAL-FLAC'] as $productNumber) {
            static::assertSame('digital', $variants[$productNumber]['type']);
            static::assertCount(1, $variants[$productNumber]['downloads']);
        }

        static::assertSame('physical', $variants['SWAG-AC-TEST-DIGITAL-VINYL']['type']);
        static::assertArrayNotHasKey('downloads', $variants['SWAG-AC-TEST-DIGITAL-VINYL']);
        static::assertArrayNotHasKey('maxPurchase', $variants['SWAG-AC-TEST-DIGITAL-VINYL']);
    }

    public function testTierPricesUseTheSalesChannelRuleAndNetPricesFollowTheTaxRate(): void
    {
        $products = $this->createProducts();

        $tiers = $products['SWAG-AC-TEST-TIER-PRICES']['prices'];
        static::assertSame([1, 5, 10], array_column($tiers, 'quantityStart'));
        static::assertSame([4, 9, null], array_column($tiers, 'quantityEnd'));
        static::assertSame([TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL)], array_values(array_unique(array_column($tiers, 'ruleId'))));
        static::assertSame(['currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 'gross' => 26.99, 'net' => 22.68, 'linked' => true], $tiers[1]['price'][0]);
    }

    public function testCustomFieldsAndPropertiesAreAssigned(): void
    {
        $products = $this->createProducts();

        $customFields = $products['SWAG-AC-TEST-CUSTOM-FIELDS'];
        static::assertSame([['id' => TestDataIds::id(FoundationSeeder::CUSTOM_FIELD_SET)]], $customFields['customFieldSets']);
        static::assertSame([
            TestDataIds::MARKER => true,
            FoundationSeeder::CUSTOM_FIELD_CARE_NOTE => 'Wash cold, dry flat.',
            FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS => 2,
            FoundationSeeder::CUSTOM_FIELD_RECYCLABLE => true,
        ], $customFields['customFields']);

        static::assertSame(
            [['id' => TestDataIds::id('material.recycled-paper')], ['id' => TestDataIds::id('material.linen')]],
            $products['SWAG-AC-TEST-PROPERTIES']['properties'],
        );
    }

    public function testEveryParentIsMarkedAndVisibleInTheTargetSalesChannels(): void
    {
        $products = $this->createProducts();

        static::assertCount(\count(ProductSeeder::PARENTS), $products);
        foreach ($products as $product) {
            static::assertTrue($product['customFields'][TestDataIds::MARKER]);
            static::assertStringStartsWith(TestDataIds::NAME_PREFIX, $product['name']);
            static::assertSame(
                [['salesChannelId' => self::SALES_CHANNEL_ID, 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL]],
                $product['visibilities'],
            );
        }
    }

    public function testCreateFailsWithoutTax(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<TaxCollection> $taxRepository */
        $taxRepository = new StaticEntityRepository([new TaxCollection()]);

        $this->expectException(TestDataException::class);

        (new ProductSeeder($productRepository, new TestDataTax($taxRepository)))->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());
    }

    public function testRemoveDeletesTheExistingParentsOnly(): void
    {
        $existingId = TestDataIds::id(ProductSeeder::PHYSICAL);
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[$existingId], []]);
        $seeder = new ProductSeeder($productRepository, new TestDataTax(TaxFixture::repository()));

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $existingId]]], $productRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testExistsChecksThePhysicalParent(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[TestDataIds::id(ProductSeeder::PHYSICAL)], []]);
        $seeder = new ProductSeeder($productRepository, new TestDataTax(TaxFixture::repository()));

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function createProducts(): array
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([]);

        (new ProductSeeder($productRepository, new TestDataTax(TaxFixture::repository())))
            ->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());

        static::assertCount(1, $productRepository->creates);

        return self::byProductNumber($productRepository->creates[0]);
    }

    /**
     * @param array<mixed> $products
     *
     * @return array<string, array<string, mixed>>
     */
    private static function byProductNumber(array $products): array
    {
        return array_column($products, null, 'productNumber');
    }
}
