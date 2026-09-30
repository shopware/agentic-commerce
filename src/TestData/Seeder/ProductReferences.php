<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;
use Shopware\Core\System\Unit\UnitCollection;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * Units and delivery times the shop lacks are created with ids `--remove` derives without the catalogue.
 *
 * @phpstan-import-type ReferenceIds from ProductPayloadBuilder
 *
 * @internal
 */
#[Package('framework')]
final class ProductReferences
{
    use DeletesExistingIds;

    /** @var array<string, array{en: string, de: string, shortCodeDe: string}> */
    private const UNITS = [
        'kg' => ['en' => 'Kilogram', 'de' => 'Kilogramm', 'shortCodeDe' => 'kg'],
        'g' => ['en' => 'Gram', 'de' => 'Gramm', 'shortCodeDe' => 'g'],
        'l' => ['en' => 'Litre', 'de' => 'Liter', 'shortCodeDe' => 'l'],
        'ml' => ['en' => 'Millilitre', 'de' => 'Milliliter', 'shortCodeDe' => 'ml'],
        'pc' => ['en' => 'Piece', 'de' => 'Stück', 'shortCodeDe' => 'Stk.'],
    ];

    /** @var array<string, array{min: int, max: int, unit: string, en: string, de: string}> */
    private const DELIVERY_TIMES = [
        '1-3-day' => ['min' => 1, 'max' => 3, 'unit' => 'day', 'en' => '1-3 days', 'de' => '1-3 Tage'],
        '2-5-day' => ['min' => 2, 'max' => 5, 'unit' => 'day', 'en' => '2-5 days', 'de' => '2-5 Tage'],
        '1-2-week' => ['min' => 1, 'max' => 2, 'unit' => 'week', 'en' => '1-2 weeks', 'de' => '1-2 Wochen'],
    ];

    /**
     * @param EntityRepository<ProductManufacturerCollection> $manufacturerRepository
     * @param EntityRepository<UnitCollection>                $unitRepository
     * @param EntityRepository<DeliveryTimeCollection>        $deliveryTimeRepository
     */
    public function __construct(
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $unitRepository,
        private readonly EntityRepository $deliveryTimeRepository,
        private readonly TestDataTax $tax,
        private readonly ShopLanguagesLoader $shopLanguagesLoader,
        private readonly CategoryTreeIds $categoryTreeIds,
    ) {
    }

    public function createMissing(PickedProducts $pickedProducts, Context $context): void
    {
        $shopLanguages = $this->shopLanguagesLoader->load($context);
        $referenceIds = $this->referenceIds($pickedProducts, $context);

        $manufacturersToWrite = [];
        foreach (array_keys($this->firstRoleByManufacturer($pickedProducts)) as $manufacturerKey) {
            $manufacturer = $pickedProducts->catalogue->manufacturerByKey[$manufacturerKey] ?? null;
            if (null !== $manufacturer) {
                $manufacturersToWrite[] = [
                    'id' => $referenceIds['manufacturer'][$manufacturerKey],
                    'name' => $manufacturer['name'],
                    ...$shopLanguages->translatedFields(['description' => $manufacturer['description']]),
                    'customFields' => TestDataIds::markerCustomField(),
                ];
            }
        }
        if ([] !== $manufacturersToWrite) {
            $this->manufacturerRepository->upsert($manufacturersToWrite, $context);
        }

        $missingUnits = [];
        foreach ($referenceIds['unit'] as $code => $unitId) {
            if ($unitId === TestDataIds::id('unit.'.$code)) {
                $missingUnits[] = ['id' => $unitId, ...$shopLanguages->translatedFields([
                    'shortCode' => new TranslatedText($code, self::UNITS[$code]['shortCodeDe']),
                    'name' => new TranslatedText(self::UNITS[$code]['en'], self::UNITS[$code]['de']),
                ])];
            }
        }
        if ([] !== $missingUnits) {
            $this->unitRepository->upsert($missingUnits, $context);
        }

        $missingDeliveryTimes = [];
        foreach ($referenceIds['deliveryTime'] as $deliveryTimeKey => $deliveryTimeId) {
            $knownDeliveryTime = self::DELIVERY_TIMES[$deliveryTimeKey] ?? null;
            if (null !== $knownDeliveryTime && $deliveryTimeId === TestDataIds::id('delivery-time.'.$deliveryTimeKey)) {
                $missingDeliveryTimes[] = [
                    'id' => $deliveryTimeId,
                    'min' => $knownDeliveryTime['min'],
                    'max' => $knownDeliveryTime['max'],
                    'unit' => $knownDeliveryTime['unit'],
                    ...$shopLanguages->translatedFields(['name' => new TranslatedText($knownDeliveryTime['en'], $knownDeliveryTime['de'])]),
                ];
            }
        }
        if ([] !== $missingDeliveryTimes) {
            $this->deliveryTimeRepository->upsert($missingDeliveryTimes, $context);
        }
    }

    /**
     * @param list<string> $salesChannelIds
     */
    public function payloadBuilder(PickedProducts $pickedProducts, array $salesChannelIds, Context $context): ProductPayloadBuilder
    {
        $standardTax = $this->tax->standardTax($context);

        return new ProductPayloadBuilder(
            $pickedProducts,
            $salesChannelIds,
            ['standard' => $standardTax, 'reduced' => $this->tax->reducedTax($standardTax, $context)],
            $this->shopLanguagesLoader->load($context),
            [...$this->referenceIds($pickedProducts, $context), 'categoryRoot' => $this->categoryTreeIds->navigationRootIds($salesChannelIds, $context)],
        );
    }

    public function remove(Context $context): bool
    {
        $hasRemovedAny = $this->deleteExisting($this->manufacturerRepository, array_map(static fn (string $role): string => TestDataIds::id('manufacturer.'.$role), PickedProducts::ROLES), $context);
        $hasRemovedAny = $this->deleteExisting($this->unitRepository, array_map(static fn (string $code): string => TestDataIds::id('unit.'.$code), array_keys(self::UNITS)), $context) || $hasRemovedAny;

        return $this->deleteExisting($this->deliveryTimeRepository, array_map(static fn (string $deliveryTimeKey): string => TestDataIds::id('delivery-time.'.$deliveryTimeKey), array_keys(self::DELIVERY_TIMES)), $context) || $hasRemovedAny;
    }

    /**
     * @return ReferenceIds
     */
    private function referenceIds(PickedProducts $pickedProducts, Context $context): array
    {
        $manufacturerIds = array_map(static fn (string $role): string => TestDataIds::id('manufacturer.'.$role), $this->firstRoleByManufacturer($pickedProducts));

        $unitIds = [];
        $deliveryTimeIds = [];
        foreach ($pickedProducts->productByRole as $product) {
            if (null !== $product->unit && isset(self::UNITS[$product->unit['code']])) {
                $unitIds[$product->unit['code']] ??= $this->unitId($product->unit['code'], $context);
            }

            $productDeliveryTimes = array_filter([$product->deliveryTime, ...array_map(static fn ($option): ?array => $option->deliveryTime, $product->variantOptions)]);
            foreach ($productDeliveryTimes as $deliveryTime) {
                $deliveryTimeKey = ProductPayloadBuilder::deliveryTimeKey($deliveryTime);
                if (isset(self::DELIVERY_TIMES[$deliveryTimeKey])) {
                    $deliveryTimeIds[$deliveryTimeKey] ??= $this->deliveryTimeId($deliveryTimeKey, $context);
                }
            }
        }

        return ['manufacturer' => $manufacturerIds, 'unit' => $unitIds, 'deliveryTime' => $deliveryTimeIds, 'categoryRoot' => []];
    }

    /**
     * @return array<string, string>
     */
    private function firstRoleByManufacturer(PickedProducts $pickedProducts): array
    {
        $roleByManufacturer = [];
        foreach ($pickedProducts->productByRole as $role => $product) {
            if (null !== $product->manufacturer) {
                $roleByManufacturer[$product->manufacturer] ??= $role;
            }
        }

        return $roleByManufacturer;
    }

    private function unitId(string $code, Context $context): string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('shortCode', $code))->setLimit(1);

        return $this->unitRepository->searchIds($criteria, $context)->firstId() ?? TestDataIds::id('unit.'.$code);
    }

    private function deliveryTimeId(string $deliveryTimeKey, Context $context): string
    {
        $knownDeliveryTime = self::DELIVERY_TIMES[$deliveryTimeKey];
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('min', $knownDeliveryTime['min']), new EqualsFilter('max', $knownDeliveryTime['max']), new EqualsFilter('unit', $knownDeliveryTime['unit']))
            ->setLimit(1);

        return $this->deliveryTimeRepository->searchIds($criteria, $context)->firstId() ?? TestDataIds::id('delivery-time.'.$deliveryTimeKey);
    }
}
