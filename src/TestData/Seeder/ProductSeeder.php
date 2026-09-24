<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * Products without plugin dependencies: physical and digital parents with mixed variants, tier prices,
 * custom fields and properties.
 *
 * `type` is sent on every product: Shopware before 6.7.7 ignores the unknown key and derives the digital
 * state from the downloads alone. Downloads are not inherited, so each digital variant carries its own.
 *
 * @phpstan-import-type TestDataPrice from TestDataTax
 *
 * @internal
 */
#[Package('framework')]
final class ProductSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const PHYSICAL = 'product.physical';
    public const PHYSICAL_A5 = 'product.physical.printed-a5';
    public const PHYSICAL_A4 = 'product.physical.printed-a4';
    public const PHYSICAL_PDF = 'product.physical.pdf';
    public const DIGITAL = 'product.digital';
    public const DIGITAL_MP3 = 'product.digital.mp3';
    public const DIGITAL_FLAC = 'product.digital.flac';
    public const DIGITAL_VINYL = 'product.digital.vinyl';
    public const TIER_PRICES = 'product.tier-prices';
    public const CUSTOM_FIELDS = 'product.custom-fields';
    public const PROPERTIES = 'product.properties';

    public const PARENTS = [self::PHYSICAL, self::DIGITAL, self::TIER_PRICES, self::CUSTOM_FIELDS, self::PROPERTIES];

    // Literal values: the constants only exist from Shopware 6.7.7.
    private const TYPE_PHYSICAL = 'physical';
    private const TYPE_DIGITAL = 'digital';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly TestDataTax $tax,
    ) {
    }

    public function label(): string
    {
        return 'Products: physical/digital with variants, advanced prices, custom fields, properties';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->productRepository, TestDataIds::id(self::PHYSICAL), $context);
    }

    public function create(array $salesChannelIds, Context $context): array
    {
        $tax = $this->tax->resolve($context);
        $price = static fn (float $gross): array => TestDataTax::price($gross, $tax);
        $base = [
            'taxId' => $tax->getId(),
            'active' => true,
            'customFields' => TestDataIds::marker(),
            'visibilities' => self::visibilities($salesChannelIds),
        ];

        $this->productRepository->create([
            [
                ...$base,
                ...$this->product(self::PHYSICAL, 'PHYSICAL', 'Notebook', $price(19.99), self::TYPE_PHYSICAL),
                'description' => 'Physical product with two printed variants and one PDF variant.',
                'weight' => 0.35,
                'width' => 148.0,
                'height' => 210.0,
                'length' => 12.0,
                'configuratorSettings' => $this->configuratorSettings(self::PHYSICAL, ['format.printed-a5', 'format.printed-a4', 'format.pdf']),
                'children' => [
                    [...$this->product(self::PHYSICAL_A5, 'PHYSICAL-A5', null, null, self::TYPE_PHYSICAL), 'options' => [['id' => TestDataIds::id('format.printed-a5')]]],
                    [
                        ...$this->product(self::PHYSICAL_A4, 'PHYSICAL-A4', null, $price(24.99), self::TYPE_PHYSICAL),
                        'weight' => 0.6,
                        'width' => 210.0,
                        'height' => 297.0,
                        'options' => [['id' => TestDataIds::id('format.printed-a4')]],
                    ],
                    [
                        ...$this->digitalVariant(self::PHYSICAL_PDF, 'PHYSICAL-PDF', $price(9.99), FoundationSeeder::MEDIA_GUIDE),
                        'options' => [['id' => TestDataIds::id('format.pdf')]],
                    ],
                ],
            ],
            [
                ...$base,
                ...$this->product(self::DIGITAL, 'DIGITAL', 'Album', $price(12.99), self::TYPE_DIGITAL),
                'description' => 'Digital product with two download variants and one vinyl variant.',
                'downloads' => [$this->download(self::DIGITAL, FoundationSeeder::MEDIA_ALBUM)],
                'configuratorSettings' => $this->configuratorSettings(self::DIGITAL, ['format.mp3', 'format.flac', 'format.vinyl']),
                'children' => [
                    [...$this->digitalVariant(self::DIGITAL_MP3, 'DIGITAL-MP3', $price(9.99), FoundationSeeder::MEDIA_ALBUM), 'options' => [['id' => TestDataIds::id('format.mp3')]]],
                    [...$this->digitalVariant(self::DIGITAL_FLAC, 'DIGITAL-FLAC', $price(12.99), FoundationSeeder::MEDIA_ALBUM), 'options' => [['id' => TestDataIds::id('format.flac')]]],
                    [
                        ...$this->product(self::DIGITAL_VINYL, 'DIGITAL-VINYL', null, $price(29.99), self::TYPE_PHYSICAL),
                        'stock' => 20,
                        'weight' => 0.25,
                        'options' => [['id' => TestDataIds::id('format.vinyl')]],
                    ],
                ],
            ],
            [
                ...$base,
                ...$this->product(self::TIER_PRICES, 'TIER-PRICES', 'Bulk Pens', $price(29.99), self::TYPE_PHYSICAL),
                'description' => 'Advanced prices by quantity: 1–4, 5–9 and 10 or more.',
                'weight' => 0.1,
                'prices' => [
                    $this->tierPrice('1', 1, 4, $price(29.99)),
                    $this->tierPrice('5', 5, 9, $price(26.99)),
                    $this->tierPrice('10', 10, null, $price(23.99)),
                ],
            ],
            [
                ...$base,
                ...$this->product(self::CUSTOM_FIELDS, 'CUSTOM-FIELDS', 'Linen Tote Bag', $price(14.99), self::TYPE_PHYSICAL),
                'description' => 'Carries values for every field of the test custom field set.',
                'weight' => 0.2,
                'customFieldSets' => [['id' => TestDataIds::id(FoundationSeeder::CUSTOM_FIELD_SET)]],
                'customFields' => [
                    ...TestDataIds::marker(),
                    FoundationSeeder::CUSTOM_FIELD_CARE_NOTE => 'Wash cold, dry flat.',
                    FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS => 2,
                    FoundationSeeder::CUSTOM_FIELD_RECYCLABLE => true,
                ],
            ],
            [
                ...$base,
                ...$this->product(self::PROPERTIES, 'PROPERTIES', 'Linen Notebook Cover', $price(17.99), self::TYPE_PHYSICAL),
                'description' => 'Carries properties that are not variant options.',
                'weight' => 0.15,
                'properties' => array_map(
                    static fn (string $optionKey): array => ['id' => TestDataIds::id($optionKey)],
                    array_keys(FoundationSeeder::MATERIAL_OPTIONS),
                ),
            ],
        ], $context);

        return [
            TestDataIds::productNumber('PHYSICAL').': variants A5 and A4 (physical), PDF (digital)',
            TestDataIds::productNumber('DIGITAL').': variants MP3 and FLAC (digital), Vinyl (physical)',
            TestDataIds::productNumber('TIER-PRICES').': 29.99 / 26.99 from 5 / 23.99 from 10',
            TestDataIds::productNumber('CUSTOM-FIELDS').': custom field set '.FoundationSeeder::CUSTOM_FIELD_SET_NAME,
            TestDataIds::productNumber('PROPERTIES').': Material properties',
        ];
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<array{salesChannelId: string, visibility: int}>
     */
    public static function visibilities(array $salesChannelIds): array
    {
        return array_map(static fn (string $salesChannelId): array => [
            'salesChannelId' => $salesChannelId,
            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
        ], $salesChannelIds);
    }

    public function remove(Context $context): bool
    {
        // Deleting a parent cascades to its variants, prices, downloads, visibilities and option mappings.
        return $this->deleteExisting($this->productRepository, array_map(TestDataIds::id(...), self::PARENTS), $context);
    }

    /**
     * @param ?TestDataPrice $price
     *
     * @return array<string, mixed>
     */
    private function product(string $productKey, string $numberSuffix, ?string $name, ?array $price, string $type): array
    {
        $product = [
            'id' => TestDataIds::id($productKey),
            'productNumber' => TestDataIds::productNumber($numberSuffix),
            'stock' => 100,
            'type' => $type,
        ];

        if (null !== $name) {
            $product['name'] = TestDataIds::name($name);
        }

        if (null !== $price) {
            $product['price'] = [$price];
        }

        return $product;
    }

    /**
     * @param TestDataPrice $price
     *
     * @return array<string, mixed>
     */
    private function digitalVariant(string $productKey, string $numberSuffix, array $price, string $mediaKey): array
    {
        return [
            ...$this->product($productKey, $numberSuffix, null, $price, self::TYPE_DIGITAL),
            'stock' => 1000,
            'maxPurchase' => 1,
            'shippingFree' => true,
            'downloads' => [$this->download($productKey, $mediaKey)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function download(string $productKey, string $mediaKey): array
    {
        return ['id' => TestDataIds::id($productKey.'.download'), 'mediaId' => TestDataIds::id($mediaKey), 'position' => 0];
    }

    /**
     * @param list<string> $optionKeys
     *
     * @return list<array<string, mixed>>
     */
    private function configuratorSettings(string $productKey, array $optionKeys): array
    {
        return array_map(static fn (string $optionKey): array => [
            'id' => TestDataIds::id($productKey.'.configurator.'.$optionKey),
            'optionId' => TestDataIds::id($optionKey),
        ], $optionKeys);
    }

    /**
     * @param TestDataPrice $price
     *
     * @return array<string, mixed>
     */
    private function tierPrice(string $tierKey, int $quantityStart, ?int $quantityEnd, array $price): array
    {
        return [
            'id' => TestDataIds::id(self::TIER_PRICES.'.price.'.$tierKey),
            'ruleId' => TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL),
            'quantityStart' => $quantityStart,
            'quantityEnd' => $quantityEnd,
            'price' => [$price],
        ];
    }
}
