<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * Candidates are ordered by a hash of seed, role and product id, so a seed repeats its choice without touching PHP's
 * global random state.
 *
 * @internal
 */
#[Package('framework')]
final class ProductPicker
{
    public function pick(CatalogueArchive $archive, int $seed): PickedProducts
    {
        $candidatesByRole = [];
        foreach (self::roleRequirements() as $role => $fits) {
            $candidates = array_values(array_filter($archive->catalogue->products, $fits));
            if ([] === $candidates) {
                throw TestDataException::invalidCatalogue('products', 'a product for the role '.$role);
            }

            usort($candidates, static fn (CatalogueProduct $a, CatalogueProduct $b): int => hash('sha256', $seed.':'.$role.':'.$a->id) <=> hash('sha256', $seed.':'.$role.':'.$b->id));
            $candidatesByRole[$role] = $candidates;
        }

        $productByRole = self::assignDistinctProducts($candidatesByRole, []);
        if (null === $productByRole) {
            throw TestDataException::invalidCatalogue('products', 'enough products for a different one in every role');
        }

        return new PickedProducts($archive->catalogue, $productByRole, $archive, $seed);
    }

    /**
     * @param array<string, list<CatalogueProduct>> $candidatesByRole
     * @param array<string, CatalogueProduct>       $productByRole
     *
     * @return ?array<string, CatalogueProduct> null when no assignment gives every role a different product
     */
    private static function assignDistinctProducts(array $candidatesByRole, array $productByRole): ?array
    {
        $role = array_key_first($candidatesByRole);
        if (null === $role) {
            return $productByRole;
        }

        $remaining = $candidatesByRole;
        unset($remaining[$role]);
        $takenIds = array_map(static fn (CatalogueProduct $product): string => $product->id, $productByRole);
        foreach ($candidatesByRole[$role] as $candidate) {
            if (\in_array($candidate->id, $takenIds, true)) {
                continue;
            }

            $assigned = self::assignDistinctProducts($remaining, [...$productByRole, $role => $candidate]);
            if (null !== $assigned) {
                return $assigned;
            }
        }

        return null;
    }

    /**
     * @return array<string, \Closure(CatalogueProduct): bool>
     */
    private static function roleRequirements(): array
    {
        $isPlain = static fn (CatalogueProduct $product): bool => [] === $product->variantOptions;

        return [
            ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION => static fn (CatalogueProduct $product): bool => !$product->isDigital() && $product->hasPhysicalOption() && $product->hasDigitalOption(),
            ProductSeeder::DIGITAL_WITH_PHYSICAL_OPTION => static fn (CatalogueProduct $product): bool => $product->isDigital() && $product->hasPhysicalOption() && $product->hasDigitalOption(),
            ProductSeeder::COLOUR_VARIANTS => static fn (CatalogueProduct $product): bool => !$product->isDigital() && $product->hasPhysicalOption() && !$product->hasDigitalOption(),
            ProductSeeder::PROPERTIES => static fn (CatalogueProduct $product): bool => $isPlain($product) && [] !== $product->optionKeysByGroup,
            ProductSeeder::CUSTOM_FIELDS => static fn (CatalogueProduct $product): bool => $isPlain($product) && [] !== $product->customFieldByName,
            ProductSeeder::TIER_PRICES => $isPlain,
            DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT => $isPlain,
        ];
    }
}
