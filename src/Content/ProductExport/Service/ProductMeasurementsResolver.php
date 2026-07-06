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
 * Shopware stores dimensions in millimetres and weight in kilograms; both Google
 * and OpenAI feeds accept `cm`/`in` for dimensions, so millimetres are converted to
 * centimetres. Each measurement is returned as `{value, unit, display}` so Google
 * can emit the combined `"64.2 cm"` form while OpenAI emits the value and unit in
 * separate fields. Unit-pricing measures (Google only) are only produced when a
 * product unit is assigned.
 *
 * @phpstan-type Measurement array{value: string, unit: string, display: string}
 *
 * @internal
 */
class ProductMeasurementsResolver
{
    private const MM_PER_CM = 10.0;

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

        $unitName = $unit->getTranslation('name');

        if (!\is_string($unitName) || '' === trim($unitName)) {
            return null;
        }

        return $this->format($quantity).' '.trim($unitName);
    }

    private function format(float $value): string
    {
        // Trim trailing zeros so 64.20 -> "64.2" and 1000.0 -> "1000".
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return '' === $formatted ? '0' : $formatted;
    }
}
