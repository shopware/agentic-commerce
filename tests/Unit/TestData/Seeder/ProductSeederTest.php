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
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\CategoryTreeIds;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductPayloadBuilder;
use Swag\AgenticCommerce\TestData\Seeder\ProductReferences;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\TestDataTax;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;

/**
 * @internal
 */
#[CoversClass(ProductSeeder::class)]
#[CoversClass(ProductPayloadBuilder::class)]
#[CoversClass(ProductReferences::class)]
#[CoversClass(CategoryTreeIds::class)]
#[CoversClass(TestDataTax::class)]
#[CoversClass(BuiltInCatalogue::class)]
class ProductSeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    /** @var StaticEntityRepository<ProductCollection> */
    private StaticEntityRepository $productRepository;

    private string $archivePath;

    protected function setUp(): void
    {
        $this->productRepository = new StaticEntityRepository([]);
        $this->archivePath = sys_get_temp_dir().'/swag-ac-product-seeder-'.bin2hex(random_bytes(4)).'.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->archivePath);
    }

    public function testBuiltInPhysicalProductHasPhysicalVariantsAndOneDigitalVariant(): void
    {
        $productByNumber = $this->createProducts(BuiltInCatalogue::pickedProducts());

        $parent = $productByNumber['SWAG-AC-TEST-PHYSICAL'];
        static::assertSame('physical', $parent['type']);
        static::assertArrayNotHasKey('downloads', $parent);
        static::assertArrayNotHasKey('media', $parent);
        static::assertSame(
            [TestDataIds::id('format.a5'), TestDataIds::id('format.a4'), TestDataIds::id('format.pdf')],
            array_column($parent['configuratorSettings'], 'optionId'),
        );

        $variantByNumber = self::byProductNumber($parent['children']);
        static::assertArrayNotHasKey('price', $variantByNumber['SWAG-AC-TEST-PHYSICAL-A5']);
        static::assertSame(24.99, $variantByNumber['SWAG-AC-TEST-PHYSICAL-A4']['price'][0]['gross']);
        static::assertSame([210.0, 297.0], [$variantByNumber['SWAG-AC-TEST-PHYSICAL-A4']['width'], $variantByNumber['SWAG-AC-TEST-PHYSICAL-A4']['height']]);

        $pdf = $variantByNumber['SWAG-AC-TEST-PHYSICAL-PDF'];
        static::assertSame('digital', $pdf['type']);
        static::assertSame(1, $pdf['maxPurchase']);
        static::assertSame(9.99, $pdf['price'][0]['gross']);
        static::assertSame(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), $pdf['downloads'][0]['mediaId']);
    }

    public function testBuiltInDigitalProductHasDigitalVariantsAndOnePhysicalVariant(): void
    {
        $productByNumber = $this->createProducts(BuiltInCatalogue::pickedProducts());

        $parent = $productByNumber['SWAG-AC-TEST-DIGITAL'];
        static::assertSame('digital', $parent['type']);
        static::assertSame([['id' => CategoryTreeIds::categoryId(ReferencesFixture::NAVIGATION_ROOT_ID, 'music')]], $parent['categories']);
        static::assertSame(TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), $parent['downloads'][0]['mediaId']);

        $variantByNumber = self::byProductNumber($parent['children']);
        foreach (['SWAG-AC-TEST-DIGITAL-MP3', 'SWAG-AC-TEST-DIGITAL-FLAC'] as $productNumber) {
            static::assertSame('digital', $variantByNumber[$productNumber]['type']);
            static::assertCount(1, $variantByNumber[$productNumber]['downloads']);
        }

        static::assertSame('physical', $variantByNumber['SWAG-AC-TEST-DIGITAL-VINYL']['type']);
        static::assertSame(20, $variantByNumber['SWAG-AC-TEST-DIGITAL-VINYL']['stock']);
        static::assertArrayNotHasKey('downloads', $variantByNumber['SWAG-AC-TEST-DIGITAL-VINYL']);
        static::assertArrayNotHasKey('maxPurchase', $variantByNumber['SWAG-AC-TEST-DIGITAL-VINYL']);
    }

    public function testTierPricesUseTheSalesChannelRuleAndNetPricesFollowTheTaxRate(): void
    {
        $productByNumber = $this->createProducts(BuiltInCatalogue::pickedProducts());

        $tiers = $productByNumber['SWAG-AC-TEST-TIER-PRICES']['prices'];
        static::assertSame([1, 5, 10], array_column($tiers, 'quantityStart'));
        static::assertSame([4, 9, null], array_column($tiers, 'quantityEnd'));
        static::assertSame([TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL)], array_values(array_unique(array_column($tiers, 'ruleId'))));
        static::assertSame(['currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 'gross' => 26.99, 'net' => 22.68, 'linked' => true], $tiers[1]['price'][0]);
    }

    public function testBuiltInCustomFieldsAndPropertiesAreAssigned(): void
    {
        $productByNumber = $this->createProducts(BuiltInCatalogue::pickedProducts());

        $customFields = $productByNumber['SWAG-AC-TEST-CUSTOM-FIELDS'];
        static::assertSame([['id' => TestDataIds::id(FoundationSeeder::CUSTOM_FIELD_SET)]], $customFields['customFieldSets']);
        static::assertSame([
            TestDataIds::MARKER => true,
            FoundationSeeder::CUSTOM_FIELD_CARE_NOTE => 'Wash cold, dry flat.',
            FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS => 2,
            FoundationSeeder::CUSTOM_FIELD_RECYCLABLE => true,
        ], $customFields['customFields']);

        static::assertSame(
            [['id' => TestDataIds::id('material.recycled-paper')], ['id' => TestDataIds::id('material.linen')]],
            $productByNumber['SWAG-AC-TEST-PROPERTIES']['properties'],
        );
    }

    public function testBuiltInProductsHaveNoColourVariantsAndGermanTranslations(): void
    {
        $productByNumber = $this->createProducts(BuiltInCatalogue::pickedProducts(), hasGerman: true);

        static::assertArrayNotHasKey('SWAG-AC-TEST-COLOURS', $productByNumber);
        foreach ($productByNumber as $product) {
            static::assertStringStartsWith(TestDataIds::NAME_PREFIX, $product['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]['name']);
            static::assertArrayNotHasKey(TestDataIds::MARKER, $product['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]['customFields'] ?? [], 'The marker lives in the system language only.');
            static::assertTrue($product['customFields'][TestDataIds::MARKER]);
            static::assertStringStartsWith(TestDataIds::NAME_PREFIX, $product['name']);
            static::assertSame(
                [['salesChannelId' => self::SALES_CHANNEL_ID, 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL]],
                $product['visibilities'],
            );
        }
    }

    public function testCatalogueProductsCarryGermanTextsImagesAndShopDetails(): void
    {
        $productByNumber = $this->createProducts($this->catalogueSelection(), hasGerman: true);

        $physical = $productByNumber['SWAG-AC-TEST-PHYSICAL'];
        static::assertSame('[AC Test] Knitted Bunny', $physical['name']);
        static::assertSame('<p>knitted-bunny</p>', $physical['description']);
        static::assertSame(['name' => '[AC Test] DE knitted-bunny', 'description' => '<p>DE knitted-bunny</p>', 'keywords' => 'knitted-bunny', 'metaTitle' => 'knitted-bunny', 'metaDescription' => 'knitted-bunny', 'customFields' => [
            FoundationSeeder::CUSTOM_FIELD_CARE_NOTE => 'Handwäsche.',
            FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS => 1,
        ]], $physical['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);
        static::assertSame('2000000000001', $physical['ean']);
        static::assertSame(TestDataIds::id('manufacturer.'.ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION), $physical['manufacturerId']);
        static::assertSame(
            [['id' => CategoryTreeIds::categoryId(ReferencesFixture::NAVIGATION_ROOT_ID, 'toys-games')], ['id' => CategoryTreeIds::categoryId(ReferencesFixture::NAVIGATION_ROOT_ID, 'curiosities-gifts')]],
            $physical['categories'],
        );
        static::assertSame(TestDataIds::id('delivery-time.1-3-day'), $physical['deliveryTimeId']);

        static::assertSame(
            [TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'cover'), TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'lifestyle'), TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'scale')],
            array_column($physical['media'], 'mediaId'),
        );
        static::assertSame($physical['media'][0]['id'], $physical['coverId']);

        $variantByNumber = self::byProductNumber($physical['children']);
        static::assertArrayNotHasKey('media', $variantByNumber['SWAG-AC-TEST-PHYSICAL-CREAM'], 'A variant in the parent look inherits its images.');
        static::assertSame(
            [TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'variant-pink'), TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'lifestyle'), TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'scale')],
            array_column($variantByNumber['SWAG-AC-TEST-PHYSICAL-PINK']['media'], 'mediaId'),
        );
        static::assertSame(12.99, $variantByNumber['SWAG-AC-TEST-PHYSICAL-PINK']['price'][0]['gross']);
        static::assertSame('digital', $variantByNumber['SWAG-AC-TEST-PHYSICAL-PATTERN-PDF']['type']);
        static::assertSame(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), $variantByNumber['SWAG-AC-TEST-PHYSICAL-PATTERN-PDF']['downloads'][0]['mediaId']);

        $digital = self::byProductNumber($productByNumber['SWAG-AC-TEST-DIGITAL']['children']);
        static::assertSame(0.3, $digital['SWAG-AC-TEST-DIGITAL-PARCHMENT']['weight']);
        static::assertSame(TestDataIds::id('delivery-time.2-5-day'), $digital['SWAG-AC-TEST-DIGITAL-PARCHMENT']['deliveryTimeId']);

        static::assertContains(
            array_column($productByNumber['SWAG-AC-TEST-COLOURS']['configuratorSettings'], 'optionId'),
            [[TestDataIds::id('colour.orange'), TestDataIds::id('colour.grey')], [TestDataIds::id('colour.green'), TestDataIds::id('colour.yellow')]],
        );
    }

    public function testTheCoverRoleDecidesTheCoverWhereverTheImageIsListed(): void
    {
        $catalogue = CatalogueFixture::catalogue();
        $catalogue['products'][0]['images'] = array_reverse($catalogue['products'][0]['images']);
        $pickedProducts = (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($this->archivePath, $catalogue)), 1);

        $physical = $this->createProducts($pickedProducts)['SWAG-AC-TEST-PHYSICAL'];

        static::assertSame(TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'cover'), $physical['media'][0]['mediaId']);
        static::assertSame($physical['media'][0]['id'], $physical['coverId']);
    }

    public function testFoodTakesTheReducedTaxAListPriceAndAUnitPrice(): void
    {
        $productByNumber = $this->createProducts($this->catalogueSelection());
        $honey = array_values(array_filter($productByNumber, static fn (array $product): bool => '[AC Test] Wildflower Honey' === $product['name']))[0];

        static::assertSame(TaxFixture::REDUCED_TAX_ID, $honey['taxId']);
        static::assertSame(12.99, $honey['price'][0]['listPrice']['gross']);
        static::assertSame(10.27, $honey['price'][0]['net']);
        static::assertSame(TestDataIds::id('unit.kg'), $honey['unitId']);
        static::assertSame([0.5, 1.0], [$honey['purchaseUnit'], $honey['referenceUnit']]);
        static::assertSame([1, 10, 1], [$honey['minPurchase'], $honey['maxPurchase'], $honey['purchaseSteps']]);
    }

    public function testCrossSellingsOnlyPointAtProductsThisSeederCreated(): void
    {
        $fixture = new ReferencesFixture();
        $pickedProducts = $this->catalogueSelection();
        (new ProductSeeder($this->productRepository, $fixture->productReferences))->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());

        static::assertCount(1, $this->productRepository->upserts);
        foreach ($this->productRepository->upserts[0] as $update) {
            foreach ($update['crossSellings'][0]['assignedProducts'] as $assigned) {
                static::assertContains($assigned['productId'], array_map(TestDataIds::id(...), ProductSeeder::PARENT_ROLES));
            }
        }

        static::assertSame('Pocket Meadow Toys', $fixture->manufacturerRepository->upserts[0][0]['name']);
        static::assertSame(TestDataIds::id('unit.kg'), $fixture->unitRepository->upserts[0][0]['id']);
    }

    public function testRemoveDeletesTheExistingParentsAndTheCreatedReferences(): void
    {
        $existingId = TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION);
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[$existingId], []]);
        $seeder = new ProductSeeder($productRepository, (new ReferencesFixture())->productReferences);

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $existingId]]], $productRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testExistsChecksThePhysicalParent(): void
    {
        /** @var StaticEntityRepository<ProductCollection> $productRepository */
        $productRepository = new StaticEntityRepository([[TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION)], []]);
        $seeder = new ProductSeeder($productRepository, (new ReferencesFixture())->productReferences);

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function createProducts(PickedProducts $pickedProducts, bool $hasGerman = false): array
    {
        (new ProductSeeder($this->productRepository, (new ReferencesFixture($hasGerman))->productReferences))
            ->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());

        static::assertCount(1, $this->productRepository->creates);

        return self::byProductNumber($this->productRepository->creates[0]);
    }

    private function catalogueSelection(): PickedProducts
    {
        return (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($this->archivePath, CatalogueFixture::catalogue())), 1);
    }

    /**
     * @param array<mixed> $productByNumber
     *
     * @return array<string, array<string, mixed>>
     */
    private static function byProductNumber(array $productByNumber): array
    {
        return array_column($productByNumber, null, 'productNumber');
    }
}
