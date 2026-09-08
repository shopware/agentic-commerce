<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Service;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/**
 * Resolves a product's "Measurements & packaging" fields into feed-ready values.
 *
 * Dimensions are converted from Shopware's millimetres to centimetres. Each value is
 * returned as `{value, unit, display}` so Google can emit the combined `"64.2 cm"`
 * form while OpenAI emits value and unit separately. Unit pricing is Google-only and
 * emitted only for units Google accepts, so free-text units never break the feed.
 *
 * @phpstan-type Measurement array{value: string, unit: string, display: string}
 *
 * @internal
 */
final class ProductMeasurementsResolver
{
    private const MM_PER_CM = 10.0;

    /**
     * @see https://support.google.com/merchants/answer/6324455
     *
     * @var list<string>
     */
    private const GOOGLE_UNIT_PRICING_UNITS = [
        'oz', 'lb', 'mg', 'g', 'kg',
        'floz', 'pt', 'qt', 'gal', 'ml', 'cl', 'l', 'cbm',
        'in', 'ft', 'yd', 'cm', 'm',
        'sqft', 'sqm',
        'ct', 'sheet', 'item',
    ];

    /**
     * @return array{
     *     weight: ?Measurement,
     *     length: ?Measurement,
     *     width: ?Measurement,
     *     height: ?Measurement,
     *     unitPricingMeasure: ?string,
     *     unitPricingBaseMeasure: ?string
     * }
     */
    public function resolve(SalesChannelProductEntity $product): array
    {
        return [
            'weight' => $this->weight($product->getWeight()),
            'length' => $this->dimension($product->getLength()),
            'width' => $this->dimension($product->getWidth()),
            'height' => $this->dimension($product->getHeight()),
            'unitPricingMeasure' => $this->unitPricing($product, $product->getPurchaseUnit()),
            'unitPricingBaseMeasure' => $this->unitPricing($product, $product->getReferenceUnit()),
        ];
    }

    /**
     * @return Measurement|null
     */
    private function weight(?float $weight): ?array
    {
        if (null === $weight || $weight <= 0.0) {
            return null;
        }

        return $this->measurement($this->format($weight), 'kg');
    }

    /**
     * @return Measurement|null
     */
    private function dimension(?float $millimetres): ?array
    {
        if (null === $millimetres || $millimetres <= 0.0) {
            return null;
        }

        return $this->measurement($this->format($millimetres / self::MM_PER_CM), 'cm');
    }

    /**
     * @return Measurement
     */
    private function measurement(string $value, string $unit): array
    {
        return ['value' => $value, 'unit' => $unit, 'display' => $value.' '.$unit];
    }

    private function unitPricing(SalesChannelProductEntity $product, ?float $quantity): ?string
    {
        $unit = $product->getUnit();

        if (null === $unit || null === $quantity || $quantity <= 0.0) {
            return null;
        }

        $shortCode = $unit->getTranslation('shortCode');

        if (!\is_string($shortCode)) {
            return null;
        }

        $code = strtolower(trim($shortCode));

        if (!\in_array($code, self::GOOGLE_UNIT_PRICING_UNITS, true)) {
            // Omit rather than emit a free-text unit that would invalidate the feed.
            return null;
        }

        // Google allows at most 2 decimals here.
        return $this->format(round($quantity, 2)).' '.$code;
    }

    private function format(float $value): string
    {
        // Trim trailing zeros so 64.20 -> "64.2" and 1000.0 -> "1000".
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }
}
