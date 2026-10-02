<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueImage;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * A Shopware Commercial grouped bundle of two physical test products. Commercial derives the bundle's
 * price and stock from its items on indexing, so the written price is a placeholder.
 *
 * @internal
 */
#[Package('framework')]
final class BundleSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const BUNDLE_PRODUCT = 'product.bundle';

    public const CMS_DETAIL_PAGE_CONSTANT = 'Shopware\\Commercial\\ProductBundles\\Defaults::CMS_PRODUCT_BUNDLE_DETAIL_PAGE';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly ProductReferences $productReferences,
        private readonly TestDataEnvironment $environment,
    ) {
    }

    public function label(): string
    {
        return 'Product bundle (Shopware Commercial)';
    }

    public function unavailableReason(): ?string
    {
        return $this->environment->bundleUnavailableReason();
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->productRepository, TestDataIds::id(self::BUNDLE_PRODUCT), $context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $builder = $this->productReferences->payloadBuilder($pickedProducts, $salesChannelIds, $context);
        $physicalProduct = $pickedProducts->requireProduct(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION);
        $defaultVariant = $physicalProduct->variantOptions[0] ?? null;
        $companionProduct = $pickedProducts->requireProduct(ProductSeeder::CUSTOM_FIELDS);
        $defaultVariantName = $defaultVariant->name ?? $physicalProduct->name;
        $name = TestDataIds::prefixedTranslatedName(new TranslatedText(
            \sprintf('Bundle: %s + %s', $physicalProduct->name->english, $companionProduct->name->english),
            \sprintf('Bundle: %s + %s', $physicalProduct->name->german, $companionProduct->name->german),
        ));
        $description = new TranslatedText(
            \sprintf('10 %% off for %s (%s) together with %s.', $physicalProduct->name->english, $defaultVariantName->english, $companionProduct->name->english),
            \sprintf('10 %% Rabatt auf %s (%s) zusammen mit %s.', $physicalProduct->name->german, $defaultVariantName->german, $companionProduct->name->german),
        );

        $bundle = [
            'id' => TestDataIds::id(self::BUNDLE_PRODUCT),
            'productNumber' => TestDataIds::productNumber('BUNDLE'),
            ...$builder->translatedFields(['name' => $name, 'description' => $description]),
            'type' => TestDataEnvironment::BUNDLE_PRODUCT_TYPE,
            'active' => true,
            'stock' => 0,
            'taxId' => $builder->taxOf($physicalProduct)->getId(),
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 0.0, 'net' => 0.0, 'linked' => true]],
            'customFields' => TestDataIds::markerCustomField(),
            'visibilities' => ProductPayloadBuilder::visibilityPayloads($salesChannelIds),
            ...$builder->mediaPayload(self::BUNDLE_PRODUCT.'.media', ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, array_filter([$physicalProduct->imageWithRole(CatalogueImage::ROLE_COVER)])),
            'bundleItems' => [
                [
                    ...$this->bundleItemPayload('notebook', ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION, 1),
                    'defaultVariantId' => null === $defaultVariant ? null : TestDataIds::id(ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION.'.'.$defaultVariant->key),
                    'defaultVariantVersionId' => Defaults::LIVE_VERSION,
                ],
                $this->bundleItemPayload('tote-bag', ProductSeeder::CUSTOM_FIELDS, 2),
            ],
            'bundleDiscounts' => [[
                'id' => TestDataIds::id(self::BUNDLE_PRODUCT.'.discount'),
                'type' => 'percentage',
                'value' => 10.0,
                'active' => true,
                'currencyId' => null,
            ]],
            'bundleConfiguration' => [
                'id' => TestDataIds::id(self::BUNDLE_PRODUCT.'.configuration'),
                'priority' => 1,
            ],
        ];

        if (\defined(self::CMS_DETAIL_PAGE_CONSTANT)) {
            $bundle['cmsPageId'] = \constant(self::CMS_DETAIL_PAGE_CONSTANT);
        }

        $this->productRepository->create([$bundle], $context);

        $physicalNumber = TestDataIds::productNumber(ProductSeeder::NUMBER_SUFFIX_BY_ROLE[ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION].(null === $defaultVariant ? '' : '-'.strtoupper($defaultVariant->key)));

        return [TestDataIds::productNumber('BUNDLE').': '.$physicalNumber.' + '.TestDataIds::productNumber(ProductSeeder::NUMBER_SUFFIX_BY_ROLE[ProductSeeder::CUSTOM_FIELDS]).', 10 % off'];
    }

    public function remove(Context $context): bool
    {
        return $this->deleteExisting($this->productRepository, [TestDataIds::id(self::BUNDLE_PRODUCT)], $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleItemPayload(string $itemKey, string $productKey, int $position): array
    {
        return [
            'id' => TestDataIds::id(self::BUNDLE_PRODUCT.'.item.'.$itemKey),
            'productId' => TestDataIds::id($productKey),
            'productVersionId' => Defaults::LIVE_VERSION,
            'position' => $position,
            'quantity' => 1,
            'min' => 1,
            'max' => 1,
            'required' => true,
            'quantityLocked' => true,
        ];
    }
}
