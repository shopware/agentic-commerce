<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Tax\TaxEntity;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueImage;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueVariantOption;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * Ids derive from the role, never from the picked catalogue product.
 *
 * `type` is sent on every product: Shopware before 6.7.7 ignores the unknown key and derives the digital state
 * from the downloads alone. Downloads are not inherited, so each digital variant carries its own.
 *
 * @phpstan-import-type CustomFieldValue from ShopLanguages
 *
 * @phpstan-type ReferenceIds array{manufacturer: array<string, string>, unit: array<string, string>, deliveryTime: array<string, string>, categoryRoot: list<string>}
 *
 * @internal
 */
#[Package('framework')]
final class ProductPayloadBuilder
{
    public const CUSTOM_FIELD_PREFIX = 'swag_ac_test_';
    private const TIER_PRICES = [
        ['quantityStart' => 1, 'quantityEnd' => 4, 'shareOfPrice' => 1.0],
        ['quantityStart' => 5, 'quantityEnd' => 9, 'shareOfPrice' => 0.9],
        ['quantityStart' => 10, 'quantityEnd' => null, 'shareOfPrice' => 0.8],
    ];
    private const PARENT_SHOTS_ON_VARIANTS = ['lifestyle', 'scale'];

    /**
     * @param list<string>                                   $salesChannelIds
     * @param array{standard: TaxEntity, reduced: TaxEntity} $taxes
     * @param ReferenceIds                                   $referenceIds
     */
    public function __construct(
        private readonly PickedProducts $pickedProducts,
        private readonly array $salesChannelIds,
        private readonly array $taxes,
        private readonly ShopLanguages $shopLanguages,
        private readonly array $referenceIds,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function productPayload(string $role, string $numberSuffix): array
    {
        $product = $this->pickedProducts->requireProduct($role);
        $tax = $this->taxOf($product);

        $payload = [
            'id' => TestDataIds::id($role),
            'productNumber' => TestDataIds::productNumber($numberSuffix),
            'stock' => $product->stock,
            'type' => $product->type,
            'active' => true,
            'taxId' => $tax->getId(),
            'price' => [$this->pricePayload($product->price, $product->listPrice, $tax)],
            'visibilities' => self::visibilityPayloads($this->salesChannelIds),
            ...$this->shopLanguages->translatedFields([
                'name' => TestDataIds::prefixedTranslatedName($product->name),
                'description' => $product->description,
                'keywords' => $product->keywords,
                'metaTitle' => $product->metaTitle,
                'metaDescription' => $product->metaDescription,
            ], self::prefixedCustomFields($product)),
            ...$this->detailFields($product),
        ];
        // The marker lives in the system language only.
        $payload['customFields'] = [...TestDataIds::markerCustomField(), ...$payload['customFields'] ?? []];

        if ([] !== $product->customFieldByName) {
            $payload['customFieldSets'] = [['id' => TestDataIds::id(FoundationSeeder::CUSTOM_FIELD_SET)]];
        }

        if (null !== $product->manufacturer && isset($this->referenceIds['manufacturer'][$product->manufacturer])) {
            $payload['manufacturerId'] = $this->referenceIds['manufacturer'][$product->manufacturer];
        }

        if (null !== $product->unit && isset($this->referenceIds['unit'][$product->unit['code']])) {
            $payload['unitId'] = $this->referenceIds['unit'][$product->unit['code']];
            $payload['purchaseUnit'] = $product->unit['purchase'];
            $payload['referenceUnit'] = $product->unit['reference'];
        }

        $categoryIds = [];
        foreach ($this->referenceIds['categoryRoot'] as $rootId) {
            foreach ($product->categoryKeys as $categoryKey) {
                $categoryIds[] = ['id' => CategoryTreeIds::categoryId($rootId, $categoryKey)];
            }
        }
        if ([] !== $categoryIds) {
            $payload['categories'] = $categoryIds;
        }

        if (null !== $product->purchaseLimits) {
            $payload['minPurchase'] = $product->purchaseLimits['min'];
            $payload['maxPurchase'] = $product->purchaseLimits['max'];
            $payload['purchaseSteps'] = $product->purchaseLimits['steps'];
        }

        $propertyIds = [];
        foreach ($product->optionKeysByGroup as $group => $optionKeys) {
            foreach ($optionKeys as $optionKey) {
                $propertyIds[] = ['id' => TestDataIds::propertyOptionId($group, $optionKey)];
            }
        }
        if ([] !== $propertyIds) {
            $payload['properties'] = $propertyIds;
        }

        if ($product->isDigital()) {
            $payload['downloads'] = [$this->downloadPayload($role, $product->downloadMediaKey)];
        }

        if (ProductSeeder::TIER_PRICES === $role) {
            $payload['prices'] = array_map(fn (array $tier): array => [
                'id' => TestDataIds::id($role.'.price.'.$tier['quantityStart']),
                'ruleId' => TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL),
                'quantityStart' => $tier['quantityStart'],
                'quantityEnd' => $tier['quantityEnd'],
                'price' => [$this->pricePayload(round($product->price * $tier['shareOfPrice'], 2), null, $tax)],
            ], self::TIER_PRICES);
        }

        $galleryImages = array_values(array_filter($product->images, static fn (CatalogueImage $image): bool => CatalogueImage::ROLE_VARIANT !== $image->role));
        // The first image becomes the cover, and a hand-edited catalogue may list the cover anywhere.
        usort($galleryImages, static fn (CatalogueImage $a, CatalogueImage $b): int => (int) (CatalogueImage::ROLE_COVER !== $a->role) <=> (int) (CatalogueImage::ROLE_COVER !== $b->role));
        $payload = [...$payload, ...$this->mediaPayload($role.'.media', $role, $galleryImages)];

        if (null !== $product->variantPropertyGroup && [] !== $product->variantOptions) {
            $payload['configuratorSettings'] = array_map(static fn (CatalogueVariantOption $option): array => [
                'id' => TestDataIds::id($role.'.configurator.'.$option->key),
                'optionId' => TestDataIds::propertyOptionId($product->variantPropertyGroup, $option->key),
            ], $product->variantOptions);
            $payload['children'] = array_map(fn (CatalogueVariantOption $option): array => $this->variantPayload($role, $numberSuffix, $product, $option, $tax), $product->variantOptions);
        }

        return $payload;
    }

    /**
     * @param list<string> $targetRoles
     *
     * @return ?array<string, mixed> null when none of the targets is in $targetRoles
     */
    public function crossSellingPayload(string $role, array $targetRoles): ?array
    {
        $assignedProducts = [];
        foreach ($this->pickedProducts->requireProduct($role)->crossSellingIds as $catalogueProductId) {
            $targetRole = $this->pickedProducts->roleOf($catalogueProductId);
            if (null !== $targetRole && $targetRole !== $role && \in_array($targetRole, $targetRoles, true)) {
                $assignedProducts[] = ['id' => TestDataIds::id($role.'.cross-selling.'.$targetRole), 'productId' => TestDataIds::id($targetRole), 'position' => \count($assignedProducts) + 1];
            }
        }

        if ([] === $assignedProducts) {
            return null;
        }

        return [
            'id' => TestDataIds::id($role),
            'crossSellings' => [[
                'id' => TestDataIds::id($role.'.cross-selling'),
                'type' => 'productList',
                'position' => 1,
                'active' => true,
                ...$this->shopLanguages->translatedFields(['name' => new TranslatedText('Goes well with', 'Passt gut dazu')]),
                'assignedProducts' => $assignedProducts,
            ]],
        ];
    }

    public function reportLine(string $role, string $numberSuffix): string
    {
        $product = $this->pickedProducts->requireProduct($role);
        $reportLine = TestDataIds::productNumber($numberSuffix).': '.$product->name->english;
        if ([] !== $product->variantOptions) {
            $reportLine .= ', variants '.implode(', ', array_map(static fn (CatalogueVariantOption $option): string => \sprintf('%s (%s)', $option->name->english, $option->type), $product->variantOptions));
        }
        if (ProductSeeder::TIER_PRICES === $role) {
            $reportLine .= ', '.implode(' / ', array_map(static fn (array $tier): string => \sprintf('%.2F from %d', round($product->price * $tier['shareOfPrice'], 2), $tier['quantityStart']), self::TIER_PRICES));
        }

        return $reportLine;
    }

    public function taxOf(CatalogueProduct $product): TaxEntity
    {
        return CatalogueProduct::TAX_REDUCED === $product->taxClass ? $this->taxes['reduced'] : $this->taxes['standard'];
    }

    /**
     * @return array<string, mixed>
     */
    public function pricePayload(float $gross, ?float $listGross, TaxEntity $tax): array
    {
        $price = TestDataTax::grossAndNetPrice($gross, $tax);
        if (null !== $listGross) {
            $price['listPrice'] = TestDataTax::grossAndNetPrice($listGross, $tax);
        }

        return $price;
    }

    /**
     * @param array<string, ?TranslatedText> $textByField
     *
     * @return array<string, mixed>
     */
    public function translatedFields(array $textByField): array
    {
        return $this->shopLanguages->translatedFields($textByField);
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<array{salesChannelId: string, visibility: int}>
     */
    public static function visibilityPayloads(array $salesChannelIds): array
    {
        return array_map(static fn (string $salesChannelId): array => [
            'salesChannelId' => $salesChannelId,
            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
        ], $salesChannelIds);
    }

    /**
     * @param array<CatalogueImage> $images
     *
     * @return array{media?: list<array<string, mixed>>, coverId?: string}
     */
    public function mediaPayload(string $productMediaPrefix, string $imageRole, array $images): array
    {
        if (!$this->pickedProducts->hasImages() || [] === $images) {
            return [];
        }

        $productMedia = [];
        foreach (array_values($images) as $position => $image) {
            $productMedia[] = ['id' => TestDataIds::id($productMediaPrefix.'.'.$image->shotName()), 'mediaId' => TestDataIds::mediaId($imageRole, $image->shotName()), 'position' => $position];
        }

        return ['media' => $productMedia, 'coverId' => $productMedia[0]['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantPayload(string $role, string $numberSuffix, CatalogueProduct $product, CatalogueVariantOption $option, TaxEntity $tax): array
    {
        $variantPayload = [
            'id' => TestDataIds::id($role.'.'.$option->key),
            'productNumber' => TestDataIds::productNumber($numberSuffix.'-'.strtoupper($option->key)),
            'stock' => $option->stock,
            'type' => $option->type,
            'options' => [['id' => TestDataIds::propertyOptionId((string) $product->variantPropertyGroup, $option->key)]],
            ...$option->dimensions,
        ];

        if (0.0 !== $option->priceDelta) {
            $variantPayload['price'] = [$this->pricePayload(round($product->price + $option->priceDelta, 2), null === $product->listPrice ? null : round($product->listPrice + $option->priceDelta, 2), $tax)];
        }
        if (null !== $option->gtin) {
            $variantPayload['ean'] = $option->gtin;
        }
        if (null !== $option->weight) {
            $variantPayload['weight'] = $option->weight;
        }
        if (null !== $option->deliveryTime && isset($this->referenceIds['deliveryTime'][self::deliveryTimeKey($option->deliveryTime)])) {
            $variantPayload['deliveryTimeId'] = $this->referenceIds['deliveryTime'][self::deliveryTimeKey($option->deliveryTime)];
        }

        if ($option->isDigital()) {
            $variantPayload['maxPurchase'] = 1;
            $variantPayload['shippingFree'] = true;
            $variantPayload['downloads'] = [$this->downloadPayload($role.'.'.$option->key, $product->downloadMediaKey)];
        }

        if (null !== $option->coverFile) {
            $variantImages = array_filter($product->images, static fn (CatalogueImage $image): bool => $image->file === $option->coverFile || \in_array($image->role, self::PARENT_SHOTS_ON_VARIANTS, true));
            usort($variantImages, static fn (CatalogueImage $a, CatalogueImage $b): int => (int) ($b->file === $option->coverFile) <=> (int) ($a->file === $option->coverFile));
            $variantPayload = [...$variantPayload, ...$this->mediaPayload($role.'.'.$option->key.'.media', $role, $variantImages)];
        }

        return $variantPayload;
    }

    /**
     * @param array{min: int, max: int, unit: string} $deliveryTime
     */
    public static function deliveryTimeKey(array $deliveryTime): string
    {
        return $deliveryTime['min'].'-'.$deliveryTime['max'].'-'.$deliveryTime['unit'];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailFields(CatalogueProduct $product): array
    {
        $detailFields = array_filter([
            'ean' => $product->gtin,
            'weight' => $product->weight,
            'width' => $product->width,
            'height' => $product->height,
            'length' => $product->length,
        ], static fn (string|float|null $value): bool => null !== $value);

        if (null !== $product->deliveryTime && isset($this->referenceIds['deliveryTime'][self::deliveryTimeKey($product->deliveryTime)])) {
            $detailFields['deliveryTimeId'] = $this->referenceIds['deliveryTime'][self::deliveryTimeKey($product->deliveryTime)];
        }

        return $detailFields;
    }

    /**
     * @return array<string, CustomFieldValue>
     */
    private static function prefixedCustomFields(CatalogueProduct $product): array
    {
        $prefixedCustomFields = [];
        foreach ($product->customFieldByName as $name => $value) {
            $prefixedCustomFields[self::CUSTOM_FIELD_PREFIX.$name] = $value;
        }

        return $prefixedCustomFields;
    }

    /**
     * @return array{id: string, mediaId: string, position: int}
     */
    private function downloadPayload(string $productKey, string $mediaKey): array
    {
        return ['id' => TestDataIds::id($productKey.'.download'), 'mediaId' => TestDataIds::id($mediaKey), 'position' => 0];
    }
}
