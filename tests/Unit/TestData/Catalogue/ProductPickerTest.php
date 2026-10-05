<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * @internal
 */
#[CoversClass(ProductPicker::class)]
#[CoversClass(PickedProducts::class)]
class ProductPickerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/swag-ac-product-picker-'.bin2hex(random_bytes(4)).'.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testEveryRoleGetsADifferentProductThatFitsIt(): void
    {
        $pickedProducts = (new ProductPicker())->pick($this->archive(), 42);

        static::assertSame(PickedProducts::ROLES, array_keys($pickedProducts->productByRole));
        static::assertCount(\count(PickedProducts::ROLES), array_unique(array_map(static fn (CatalogueProduct $product): string => $product->id, $pickedProducts->productByRole)));
        static::assertSame('knitted-bunny', $pickedProducts->requireProduct(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION)->id);
        static::assertSame('map-scroll', $pickedProducts->requireProduct(ProductSeeder::DIGITAL_WITH_PHYSICAL_OPTION)->id);
        static::assertContains($pickedProducts->requireProduct(ProductSeeder::COLOUR_VARIANTS)->id, ['plush-fox', 'frog-umbrella']);
        static::assertContains($pickedProducts->requireProduct(ProductSeeder::PROPERTIES)->id, ['wildflower-honey', 'cat-mug']);
        static::assertContains($pickedProducts->requireProduct(ProductSeeder::CUSTOM_FIELDS)->id, ['wildflower-honey', 'cat-mug']);
        static::assertContains($pickedProducts->requireProduct(DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT)->id, ['rune-stones', 'candle-lantern']);
        static::assertSame(42, $pickedProducts->seed);
        static::assertTrue($pickedProducts->hasImages());
    }

    public function testTheSameSeedRepeatsTheChoiceAndOtherSeedsVaryIt(): void
    {
        $picker = new ProductPicker();
        $archive = $this->archive();
        $choice = static fn (PickedProducts $pickedProducts): array => array_map(static fn (CatalogueProduct $product): string => $product->id, $pickedProducts->productByRole);

        static::assertSame($choice($picker->pick($archive, 7)), $choice($picker->pick($archive, 7)));

        $colourProducts = [];
        foreach (range(1, 20) as $seed) {
            $colourProducts[] = $picker->pick($archive, $seed)->requireProduct(ProductSeeder::COLOUR_VARIANTS)->id;
        }
        static::assertEqualsCanonicalizing(['frog-umbrella', 'plush-fox'], array_values(array_unique($colourProducts)));
    }

    public function testARoleFallsBackWhenAnEarlierRoleTookItsOnlyCandidate(): void
    {
        $catalogue = CatalogueFixture::catalogue();
        foreach ($catalogue['products'] as $index => $product) {
            if ('cat-mug' === $product['id']) {
                unset($catalogue['products'][$index]['customFields']);
            }
        }
        $archive = CatalogueArchive::open(CatalogueFixture::writeZip($this->path, $catalogue));

        foreach (range(1, 20) as $seed) {
            $pickedProducts = (new ProductPicker())->pick($archive, $seed);
            static::assertSame('wildflower-honey', $pickedProducts->requireProduct(ProductSeeder::CUSTOM_FIELDS)->id);
            static::assertSame('cat-mug', $pickedProducts->requireProduct(ProductSeeder::PROPERTIES)->id);
        }
    }

    public function testACatalogueWithoutAProductForARoleIsRejected(): void
    {
        $catalogue = CatalogueFixture::catalogue();
        array_splice($catalogue['products'], 1, 1);

        $this->expectExceptionObject(TestDataException::invalidCatalogue('products', 'a product for the role '.ProductSeeder::DIGITAL_WITH_PHYSICAL_OPTION));

        (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($this->path, $catalogue)), 1);
    }

    private function archive(): CatalogueArchive
    {
        return CatalogueArchive::open(CatalogueFixture::writeZip($this->path, CatalogueFixture::catalogue()));
    }
}
