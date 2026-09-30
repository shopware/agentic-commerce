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
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\Seeder\CategorySeeder;
use Swag\AgenticCommerce\TestData\Seeder\CategoryTreeIds;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;

/**
 * @internal
 */
#[CoversClass(CategorySeeder::class)]
#[CoversClass(CategoryTreeIds::class)]
class CategorySeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const FIRST_CATEGORY_ID = '0191c1c1c1c17000c1c1c1c1c1c1c1c1';
    private const LAST_CATEGORY_ID = '0191c2c2c2c27000c2c2c2c2c2c2c2c2';

    /** @var StaticEntityRepository<CategoryCollection> */
    private StaticEntityRepository $categoryRepository;

    protected function setUp(): void
    {
        $this->categoryRepository = new StaticEntityRepository([new CategoryCollection([self::category(self::FIRST_CATEGORY_ID, null), self::category(self::LAST_CATEGORY_ID, self::FIRST_CATEGORY_ID)])]);
    }

    public function testTheTreeHoldsTheUsedCategoriesInCatalogueOrderBelowTheNavigationRoot(): void
    {
        $path = sys_get_temp_dir().'/swag-ac-category-'.bin2hex(random_bytes(4)).'.zip';
        $pickedProducts = (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($path, CatalogueFixture::catalogue())), 1);
        unlink($path);

        $reportLines = $this->seeder(hasGerman: true)->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());

        $upsertedTree = $this->categoryRepository->upserts[0][0];
        static::assertSame(CategoryTreeIds::treeId(ReferencesFixture::NAVIGATION_ROOT_ID), $upsertedTree['id']);
        static::assertSame(ReferencesFixture::NAVIGATION_ROOT_ID, $upsertedTree['parentId']);
        static::assertSame(self::LAST_CATEGORY_ID, $upsertedTree['afterCategoryId'], 'The tree follows the last category of the root.');
        static::assertSame('[AC Test] Test products', $upsertedTree['name']);
        static::assertSame(['name' => '[AC Test] Testprodukte'], $upsertedTree['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);

        static::assertSame(['Toys & Games', 'Food & Drink', 'Curiosities & Gifts'], array_column($upsertedTree['children'], 'name'));
        static::assertSame(CategoryTreeIds::categoryId(ReferencesFixture::NAVIGATION_ROOT_ID, 'toys-games'), $upsertedTree['children'][0]['id']);
        static::assertSame([null, $upsertedTree['children'][0]['id'], $upsertedTree['children'][1]['id']], array_column($upsertedTree['children'], 'afterCategoryId'));
        static::assertSame(['name' => 'Spielwaren'], $upsertedTree['children'][0]['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);
        static::assertSame(['[AC Test] Test products with Toys & Games, Food & Drink, Curiosities & Gifts, in 1 navigation tree(s)'], $reportLines);
    }

    public function testBuiltInProductsGetOnlyTheirOwnCategories(): void
    {
        $this->seeder()->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertSame(['Stationery', 'Music', 'Bags'], array_column($this->categoryRepository->upserts[0][0]['children'], 'name'));
    }

    public function testRemoveDeletesTheChildrenAndThenEveryTestTree(): void
    {
        $treeId = CategoryTreeIds::treeId(ReferencesFixture::NAVIGATION_ROOT_ID);
        $childId = CategoryTreeIds::categoryId(ReferencesFixture::NAVIGATION_ROOT_ID, 'toys-games');
        $this->categoryRepository = new StaticEntityRepository([[$treeId], [$childId], [$childId], [$treeId], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $childId]], [['id' => $treeId]]], $this->categoryRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testExistsLooksForATestTree(): void
    {
        $this->categoryRepository = new StaticEntityRepository([[TestDataIds::id('any')], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    private static function category(string $id, ?string $afterCategoryId): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId($id);
        $category->setUniqueIdentifier($id);
        if (null !== $afterCategoryId) {
            $category->setAfterCategoryId($afterCategoryId);
        }

        return $category;
    }

    private function seeder(bool $hasGerman = false): CategorySeeder
    {
        return new CategorySeeder($this->categoryRepository, ReferencesFixture::categoryTreeIds(), ReferencesFixture::shopLanguagesLoader($hasGerman));
    }
}
