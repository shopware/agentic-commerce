<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;
use Swag\AgenticCommerce\TestData\TestDataIds;

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
        private readonly TestDataTax $tax,
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

    public function create(array $salesChannelIds, Context $context): array
    {
        $bundleId = TestDataIds::id(self::BUNDLE_PRODUCT);

        $bundle = [
            'id' => $bundleId,
            'productNumber' => TestDataIds::productNumber('BUNDLE'),
            'name' => TestDataIds::name('Notebook and Tote Bag Bundle'),
            'description' => 'Bundles the A5 notebook variant with the tote bag at 10 % off.',
            'type' => TestDataEnvironment::BUNDLE_PRODUCT_TYPE,
            'active' => true,
            'stock' => 0,
            'taxId' => $this->tax->resolve($context)->getId(),
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 0.0, 'net' => 0.0, 'linked' => true]],
            'customFields' => TestDataIds::marker(),
            'visibilities' => ProductSeeder::visibilities($salesChannelIds),
            'bundleItems' => [
                [
                    ...$this->bundleItem('notebook', ProductSeeder::PHYSICAL, 1),
                    'defaultVariantId' => TestDataIds::id(ProductSeeder::PHYSICAL_A5),
                    'defaultVariantVersionId' => Defaults::LIVE_VERSION,
                ],
                $this->bundleItem('tote-bag', ProductSeeder::CUSTOM_FIELDS, 2),
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

        return [TestDataIds::productNumber('BUNDLE').': '.TestDataIds::productNumber('PHYSICAL-A5').' + '.TestDataIds::productNumber('CUSTOM-FIELDS').', 10 % off'];
    }

    public function remove(Context $context): bool
    {
        return $this->deleteExisting($this->productRepository, [TestDataIds::id(self::BUNDLE_PRODUCT)], $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleItem(string $itemKey, string $productKey, int $position): array
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
