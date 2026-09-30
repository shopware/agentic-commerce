<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * The built-in products have no colour variants, so the offline run skips that role.
 *
 * @internal
 */
#[Package('framework')]
final class ProductSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const PHYSICAL_WITH_DIGITAL_OPTION = 'product.physical';
    public const DIGITAL_WITH_PHYSICAL_OPTION = 'product.digital';
    public const COLOUR_VARIANTS = 'product.colour-variants';
    public const TIER_PRICES = 'product.tier-prices';
    public const CUSTOM_FIELDS = 'product.custom-fields';
    public const PROPERTIES = 'product.properties';

    public const NUMBER_SUFFIX_BY_ROLE = [
        self::PHYSICAL_WITH_DIGITAL_OPTION => 'PHYSICAL',
        self::DIGITAL_WITH_PHYSICAL_OPTION => 'DIGITAL',
        self::COLOUR_VARIANTS => 'COLOURS',
        self::TIER_PRICES => 'TIER-PRICES',
        self::CUSTOM_FIELDS => 'CUSTOM-FIELDS',
        self::PROPERTIES => 'PROPERTIES',
    ];

    public const PARENT_ROLES = [self::PHYSICAL_WITH_DIGITAL_OPTION, self::DIGITAL_WITH_PHYSICAL_OPTION, self::COLOUR_VARIANTS, self::TIER_PRICES, self::CUSTOM_FIELDS, self::PROPERTIES];

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly ProductReferences $productReferences,
    ) {
    }

    public function label(): string
    {
        return 'Products: physical/digital with variants, colour variants, advanced prices, custom fields, properties';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->productRepository, TestDataIds::id(self::PHYSICAL_WITH_DIGITAL_OPTION), $context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $this->productReferences->createMissing($pickedProducts, $context);
        $builder = $this->productReferences->payloadBuilder($pickedProducts, $salesChannelIds, $context);

        $roles = array_values(array_filter(self::PARENT_ROLES, static fn (string $role): bool => null !== $pickedProducts->product($role)));
        $this->productRepository->create(array_map(static fn (string $role): array => $builder->productPayload($role, self::NUMBER_SUFFIX_BY_ROLE[$role]), $roles), $context);

        // Cross-selling targets must exist before an assignment to them is written.
        $crossSellings = array_values(array_filter(array_map(static fn (string $role): ?array => $builder->crossSellingPayload($role, $roles), $roles)));
        if ([] !== $crossSellings) {
            $this->productRepository->upsert($crossSellings, $context);
        }

        return array_map(static fn (string $role): string => $builder->reportLine($role, self::NUMBER_SUFFIX_BY_ROLE[$role]), $roles);
    }

    public function remove(Context $context): bool
    {
        // Deleting a parent cascades to its variants, prices, downloads, media, cross-sellings and option mappings.
        $hasRemovedAny = $this->deleteExisting($this->productRepository, array_map(TestDataIds::id(...), self::PARENT_ROLES), $context);

        return $this->productReferences->remove($context) || $hasRemovedAny;
    }
}
