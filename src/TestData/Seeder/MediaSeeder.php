<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * Catalogue images go into a folder of their own below the product folder, so they take its thumbnail sizes and
 * `--remove` finds them without the catalogue.
 *
 * @internal
 */
#[Package('framework')]
final class MediaSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const IMAGE_FOLDER = 'media-folder.product-images';

    /**
     * @param EntityRepository<MediaCollection>       $mediaRepository
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly MediaService $mediaService,
        private readonly ShopLanguagesLoader $shopLanguagesLoader,
    ) {
    }

    public function label(): string
    {
        return 'Download files and product images';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->mediaRepository, TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), $context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $downloadFolderId = $this->defaultFolder(ProductDownloadDefinition::ENTITY_NAME, $context)['id'] ?? null;
        $this->saveFile(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), $downloadFolderId, true, 'swag-ac-test-guide', 'pdf', 'application/pdf', self::minimalPdf(), [], $context);
        $this->saveFile(TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), $downloadFolderId, true, 'swag-ac-test-album', 'txt', 'text/plain', "Agentic Commerce test album download.\n", [], $context);
        $report = ['Downloads: swag-ac-test-guide.pdf, swag-ac-test-album.txt'];

        $archive = $pickedProducts->archive;
        if (null === $archive) {
            return [...$report, 'Images: none, the built-in products have no images'];
        }

        $imageFolderId = $this->imageFolderId($context);
        $shopLanguages = $this->shopLanguagesLoader->load($context);
        $count = 0;
        foreach ($pickedProducts->productByRole as $role => $product) {
            foreach ($product->images as $image) {
                $fields = $shopLanguages->translatedFields(['alt' => $image->alt, 'title' => $product->name]);
                $fileName = 'swag-ac-test-'.str_replace('.', '-', $role).'-'.$image->shotName();
                $this->saveFile(TestDataIds::mediaId($role, $image->shotName()), $imageFolderId, false, $fileName, 'webp', 'image/webp', $archive->readImage($image->file), $fields, $context);
                ++$count;
            }
        }

        return [...$report, \sprintf('Images: %d from catalogue %s, in the media folder %s', $count, $archive->catalogue->version, TestDataIds::prefixedName('Product images'))];
    }

    public function remove(Context $context): bool
    {
        $folderId = TestDataIds::id(self::IMAGE_FOLDER);
        $imageIds = self::stringIds($this->mediaRepository->searchIds((new Criteria())->addFilter(new EqualsFilter('mediaFolderId', $folderId)), $context));
        $hasRemovedAny = $this->deleteExisting($this->mediaRepository, [TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), ...$imageIds], $context);

        return $this->deleteExisting($this->mediaFolderRepository, [$folderId], $context) || $hasRemovedAny;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function saveFile(string $mediaId, ?string $folderId, bool $private, string $fileName, string $extension, string $contentType, string $blob, array $fields, Context $context): void
    {
        $this->mediaRepository->upsert([[
            'id' => $mediaId,
            'mediaFolderId' => $folderId,
            'private' => $private,
            'title' => TestDataIds::prefixedName($fileName),
            ...$fields,
            'customFields' => TestDataIds::markerCustomField(),
        ]], $context);

        if ($this->mediaRepository->search(new Criteria([$mediaId]), $context)->getEntities()->first()?->hasFile()) {
            return;
        }

        $this->mediaService->saveFile($blob, $extension, $contentType, $fileName, $context, null, $mediaId, $private);
    }

    private function imageFolderId(Context $context): string
    {
        $productFolder = $this->defaultFolder(ProductDefinition::ENTITY_NAME, $context);
        if (null === $productFolder) {
            throw TestDataException::missingProductMediaFolder();
        }

        $folderId = TestDataIds::id(self::IMAGE_FOLDER);
        $this->mediaFolderRepository->upsert([[
            'id' => $folderId,
            'name' => TestDataIds::prefixedName('Product images'),
            'parentId' => $productFolder['id'],
            'configurationId' => $productFolder['configurationId'],
            'useParentConfiguration' => true,
            'customFields' => TestDataIds::markerCustomField(),
        ]], $context);

        return $folderId;
    }

    /**
     * @return ?array{id: string, configurationId: string}
     */
    private function defaultFolder(string $entityName, Context $context): ?array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('defaultFolder.entity', $entityName))
            ->setLimit(1);
        $folder = $this->mediaFolderRepository->search($criteria, $context)->getEntities()->first();

        return null === $folder ? null : ['id' => $folder->getId(), 'configurationId' => $folder->getConfigurationId()];
    }

    private static function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
