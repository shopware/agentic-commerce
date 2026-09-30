<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\TestData;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\Seeder\CategorySeeder;
use Swag\AgenticCommerce\TestData\Seeder\CategoryTreeIds;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\MediaSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductReferences;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\TestDataSeederInterface;
use Swag\AgenticCommerce\TestData\Seeder\TestDataTax;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;

/**
 * @internal
 */
final class CatalogueSeedingTest extends TestCase
{
    use IntegrationTestBehaviour;

    private string $archivePath;

    protected function setUp(): void
    {
        $this->archivePath = sys_get_temp_dir().'/swag-ac-catalogue-seeding-'.bin2hex(random_bytes(4)).'.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->archivePath);
    }

    public function testCatalogueProductsAreCreatedWithImagesAndRemovedCompletely(): void
    {
        $context = Context::createDefaultContext();
        CatalogueFixture::writeZip($this->archivePath, CatalogueFixture::catalogue(), [], self::webp(...));
        $pickedProducts = (new ProductPicker())->pick(CatalogueArchive::open($this->archivePath), 3);
        $seeders = $this->seeders();

        foreach ($seeders as $seeder) {
            $seeder->create([TestDefaults::SALES_CHANNEL], $pickedProducts, $context);
        }

        $physicalCriteria = (new Criteria([TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION)]))
            ->addAssociation('media')
            ->addAssociation('children.media')
            ->addAssociation('configuratorSettings.option')
            ->addAssociation('translations')
            ->addAssociation('categories');
        $knittedBunny = $this->productRepository()->search($physicalCriteria, $context)->getEntities()->first();
        static::assertInstanceOf(ProductEntity::class, $knittedBunny);
        static::assertSame('[AC Test] Knitted Bunny', $knittedBunny->getTranslation('name'));
        static::assertSame(TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION.'.media.cover'), $knittedBunny->getCoverId());
        static::assertCount(3, $knittedBunny->getMedia() ?? []);
        static::assertEqualsCanonicalizing(['Toys & Games', 'Curiosities & Gifts'], array_values($knittedBunny->getCategories()?->map(static fn ($category): ?string => $category->getTranslation('name')) ?? []));
        static::assertCount(3, $knittedBunny->getConfiguratorSettings() ?? []);
        static::assertSame(TestDataIds::id('manufacturer.'.ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION), $knittedBunny->getManufacturerId());
        static::assertNotNull($knittedBunny->getTranslations()?->filterByProperty('name', '[AC Test] DE knitted-bunny')->first(), 'The German translation is written when the shop has de-DE.');

        $pinkVariant = $knittedBunny->getChildren()?->filter(static fn (ProductEntity $variant): bool => 'SWAG-AC-TEST-PHYSICAL-PINK' === $variant->getProductNumber())->first();
        static::assertSame(TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'variant-pink'), $pinkVariant?->getMedia()?->get((string) $pinkVariant->getCoverId())?->getMediaId());

        foreach (array_reverse($seeders) as $seeder) {
            $seeder->remove($context);
        }

        static::assertSame(0, $this->productRepository()->searchIds((new Criteria())->addFilter(new PrefixFilter('productNumber', TestDataIds::PRODUCT_NUMBER_PREFIX)), $context)->getTotal());
        static::assertSame(0, $this->repository('media.repository')->searchIds((new Criteria())->addFilter(new EqualsFilter('mediaFolderId', TestDataIds::id(MediaSeeder::IMAGE_FOLDER))), $context)->getTotal());
        static::assertSame(0, $this->repository('property_group.repository')->searchIds(new Criteria(array_map(static fn (string $group): string => TestDataIds::id('property-group.'.$group), FoundationSeeder::PROPERTY_GROUPS)), $context)->getTotal());
        static::assertSame(0, $this->repository('product_manufacturer.repository')->searchIds(new Criteria([TestDataIds::id('manufacturer.'.ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION)]), $context)->getTotal());
        static::assertSame(0, $this->repository('category.repository')->searchIds((new Criteria())->addFilter(new PrefixFilter('name', TestDataIds::NAME_PREFIX)), $context)->getTotal());
        static::assertSame(0, $this->repository('category.repository')->searchIds(new Criteria([CategoryTreeIds::categoryId($this->navigationRootId(), 'toys-games')]), $context)->getTotal());
    }

    private function navigationRootId(): string
    {
        return (string) $this->repository('sales_channel.repository')->search(new Criteria([TestDefaults::SALES_CHANNEL]), Context::createDefaultContext())->getEntities()->first()?->get('navigationCategoryId');
    }

    /**
     * @return list<TestDataSeederInterface>
     */
    private function seeders(): array
    {
        $shopLanguagesLoader = new ShopLanguagesLoader($this->repository('language.repository'));
        $tax = new TestDataTax($this->repository('tax.repository'));
        $categoryTreeIds = new CategoryTreeIds($this->repository('sales_channel.repository'));
        $productReferences = new ProductReferences($this->repository('product_manufacturer.repository'), $this->repository('unit.repository'), $this->repository('delivery_time.repository'), $tax, $shopLanguagesLoader, $categoryTreeIds);
        $mediaService = static::getContainer()->get(MediaService::class);
        static::assertInstanceOf(MediaService::class, $mediaService);

        return [
            new FoundationSeeder($this->repository('rule.repository'), $this->repository('property_group.repository'), $this->repository('custom_field_set.repository'), $shopLanguagesLoader, $this->systemConfigService()),
            new MediaSeeder($this->repository('media.repository'), $this->repository('media_folder.repository'), $mediaService, $shopLanguagesLoader),
            new CategorySeeder($this->repository('category.repository'), $categoryTreeIds, $shopLanguagesLoader),
            new ProductSeeder($this->productRepository(), $productReferences),
        ];
    }

    private function systemConfigService(): SystemConfigService
    {
        $systemConfigService = static::getContainer()->get(SystemConfigService::class);
        static::assertInstanceOf(SystemConfigService::class, $systemConfigService);

        return $systemConfigService;
    }

    private static function webp(string $file): string
    {
        $placeholder = imagecreatetruecolor(8, 8);
        static::assertNotFalse($placeholder);
        ob_start();
        imagewebp($placeholder);

        return (string) ob_get_clean();
    }

    /**
     * @return EntityRepository<ProductCollection>
     */
    private function productRepository(): EntityRepository
    {
        /** @var EntityRepository<ProductCollection> $repository */
        $repository = $this->repository('product.repository');

        return $repository;
    }

    /**
     * @return EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>>
     */
    private function repository(string $serviceId): EntityRepository
    {
        $repository = static::getContainer()->get($serviceId);
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }
}
