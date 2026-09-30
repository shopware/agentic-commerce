<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\Catalogue\Catalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;

/**
 * Which product fills which role of this run. Ids derive from the role, never from the product, so `--remove`
 * works without knowing what was picked.
 *
 * @internal
 */
#[Package('framework')]
final class PickedProducts
{
    public const ROLES = [
        ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION,
        ProductSeeder::DIGITAL_WITH_PHYSICAL_OPTION,
        ProductSeeder::COLOUR_VARIANTS,
        ProductSeeder::PROPERTIES,
        ProductSeeder::CUSTOM_FIELDS,
        ProductSeeder::TIER_PRICES,
        DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT,
    ];

    /**
     * @param array<string, CatalogueProduct> $productByRole
     * @param ?CatalogueArchive               $archive       null for the built-in products, which have no images
     */
    public function __construct(
        public readonly Catalogue $catalogue,
        public readonly array $productByRole,
        public readonly ?CatalogueArchive $archive = null,
        public readonly ?int $seed = null,
    ) {
    }

    public function product(string $role): ?CatalogueProduct
    {
        return $this->productByRole[$role] ?? null;
    }

    public function requireProduct(string $role): CatalogueProduct
    {
        return $this->productByRole[$role] ?? throw TestDataException::invalidCatalogue('products', 'a product for the role '.$role);
    }

    public function hasImages(): bool
    {
        return null !== $this->archive;
    }

    public function roleOf(string $catalogueProductId): ?string
    {
        foreach ($this->productByRole as $role => $product) {
            if ($catalogueProductId === $product->id) {
                return $role;
            }
        }

        return null;
    }
}
