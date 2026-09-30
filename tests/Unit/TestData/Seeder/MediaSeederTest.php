<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\MediaSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;

/**
 * @internal
 */
#[CoversClass(MediaSeeder::class)]
class MediaSeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const DOWNLOAD_FOLDER_ID = '0191eeeeeeee7000eeeeeeeeeeeeeeee';
    private const PRODUCT_FOLDER_ID = '0191cccccccc7000cccccccccccccccc';
    private const CONFIGURATION_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';

    /** @var StaticEntityRepository<MediaCollection> */
    private StaticEntityRepository $mediaRepository;

    /** @var StaticEntityRepository<MediaFolderCollection> */
    private StaticEntityRepository $mediaFolderRepository;

    private MediaService&MockObject $mediaService;

    /** @var list<array{string, string, string, ?string, bool}> */
    private array $savedFiles = [];

    protected function setUp(): void
    {
        $this->mediaRepository = new StaticEntityRepository(array_fill(0, 40, new MediaCollection()));
        $this->mediaFolderRepository = new StaticEntityRepository([
            new MediaFolderCollection([self::folder(self::DOWNLOAD_FOLDER_ID)]),
            new MediaFolderCollection([self::folder(self::PRODUCT_FOLDER_ID)]),
        ]);
        $this->mediaService = $this->createMock(MediaService::class);
        $this->mediaService->method('saveFile')->willReturnCallback(function (string $blob, string $extension, string $contentType, string $fileName, Context $context, ?string $folder, ?string $mediaId, bool $private): string {
            $this->savedFiles[] = [$extension, $blob, $fileName, $mediaId, $private];

            return (string) $mediaId;
        });
    }

    public function testDownloadsArePrivateMediaInTheDownloadFolderAndBuiltInProductsHaveNoImages(): void
    {
        $report = $this->seeder()->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertSame([
            ['pdf', 'swag-ac-test-guide', TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), true],
            ['txt', 'swag-ac-test-album', TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), true],
        ], array_map(static fn (array $file): array => [$file[0], $file[2], $file[3], $file[4]], $this->savedFiles));
        foreach ($this->mediaRepository->upserts as [$media]) {
            static::assertTrue($media['private']);
            static::assertSame(self::DOWNLOAD_FOLDER_ID, $media['mediaFolderId']);
        }
        static::assertSame('Images: none, the built-in products have no images', $report[1]);
    }

    public function testCatalogueImagesArePublicMediaWithAltTextsInTheirOwnFolder(): void
    {
        $path = sys_get_temp_dir().'/swag-ac-media-'.bin2hex(random_bytes(4)).'.zip';
        $pickedProducts = (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($path, CatalogueFixture::catalogue())), 1);

        $report = $this->seeder(hasGerman: true)->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());
        unlink($path);

        $folder = $this->mediaFolderRepository->upserts[0][0];
        static::assertSame(TestDataIds::id(MediaSeeder::IMAGE_FOLDER), $folder['id']);
        static::assertSame(self::PRODUCT_FOLDER_ID, $folder['parentId']);
        static::assertSame(self::CONFIGURATION_ID, $folder['configurationId']);

        $bunnyCoverId = TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'cover');
        $bunnyCover = array_values(array_filter($this->mediaRepository->upserts, static fn (array $upsert): bool => $bunnyCoverId === $upsert[0]['id']))[0][0];
        static::assertFalse($bunnyCover['private']);
        static::assertSame('knitted-bunny cover, AI-generated', $bunnyCover['alt']);
        static::assertSame(['alt' => 'knitted-bunny cover, KI-generiert', 'title' => 'DE knitted-bunny'], $bunnyCover['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);

        $bunnyFile = array_values(array_filter($this->savedFiles, static fn (array $file): bool => $bunnyCoverId === $file[3]))[0];
        static::assertSame(['webp', CatalogueFixture::imageBytes('images/knitted-bunny/cover.webp'), 'swag-ac-test-product-physical-cover', $bunnyCoverId, false], $bunnyFile);

        $catalogueImageCount = array_sum(array_map(static fn ($product): int => \count($product->images), $pickedProducts->productByRole));
        static::assertCount(2 + $catalogueImageCount, $this->savedFiles);
        static::assertSame(\sprintf('Images: %d from catalogue 1.0.0, in the media folder [AC Test] Product images', $catalogueImageCount), $report[1]);
    }

    public function testRerunKeepsFilesThatWereAlreadySaved(): void
    {
        $savedGuide = new MediaEntity();
        $savedGuide->setId(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE));
        $savedGuide->setUniqueIdentifier(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE));
        $savedGuide->setPath('media/guide/swag-ac-test-guide.pdf');
        $this->mediaRepository = new StaticEntityRepository([new MediaCollection([$savedGuide]), new MediaCollection()]);

        $this->seeder()->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertSame(['swag-ac-test-album'], array_column($this->savedFiles, 2));
    }

    public function testRemoveDeletesTheDownloadsTheImagesInTheFolderAndTheFolder(): void
    {
        $imageId = TestDataIds::mediaId(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 'cover');
        $this->mediaRepository = new StaticEntityRepository([[$imageId], [TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), $imageId], [], []]);
        $this->mediaFolderRepository = new StaticEntityRepository([[TestDataIds::id(MediaSeeder::IMAGE_FOLDER)], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => TestDataIds::id(FoundationSeeder::MEDIA_GUIDE)], ['id' => $imageId]]], $this->mediaRepository->deletes);
        static::assertSame([[['id' => TestDataIds::id(MediaSeeder::IMAGE_FOLDER)]]], $this->mediaFolderRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testExistsChecksTheLastDownloadWritten(): void
    {
        $this->mediaRepository = new StaticEntityRepository([[TestDataIds::id(FoundationSeeder::MEDIA_ALBUM)], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    private function seeder(bool $hasGerman = false): MediaSeeder
    {
        return new MediaSeeder($this->mediaRepository, $this->mediaFolderRepository, $this->mediaService, ReferencesFixture::shopLanguagesLoader($hasGerman));
    }

    private static function folder(string $id): MediaFolderEntity
    {
        $folder = new MediaFolderEntity();
        $folder->setId($id);
        $folder->setUniqueIdentifier($id);
        $folder->setConfigurationId(self::CONFIGURATION_ID);

        return $folder;
    }
}
