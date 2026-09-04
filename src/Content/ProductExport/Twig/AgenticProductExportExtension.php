<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Twig;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Content\ProductExport\Service\EssentialCharacteristicsResolver;
use Swag\AgenticCommerce\Content\ProductExport\Service\ProductMeasurementsResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes agentic product-export helpers to the (non-sandboxed) product-export Twig
 * environment. Registered automatically via `twig.extension` autoconfiguration and
 * copied into the export environment by core's StringTemplateRenderer.
 *
 * @internal
 */
#[Package('discovery')]
final class AgenticProductExportExtension extends AbstractExtension
{
    public function __construct(
        private readonly EssentialCharacteristicsResolver $essentialCharacteristicsResolver,
        private readonly ProductMeasurementsResolver $productMeasurementsResolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('agentic_essential_characteristics', $this->getEssentialCharacteristics(...)),
            new TwigFunction('agentic_product_measurements', $this->getProductMeasurements(...)),
        ];
    }

    /**
     * @return list<array{section: string, name: string, value: string}>
     */
    public function getEssentialCharacteristics(mixed $product, mixed $context): array
    {
        if (!$product instanceof SalesChannelProductEntity || !$context instanceof SalesChannelContext) {
            return [];
        }

        return $this->essentialCharacteristicsResolver->resolve($product, $context);
    }

    /**
     * @return array{weight: ?array{value: string, unit: string, display: string}, length: ?array{value: string, unit: string, display: string}, width: ?array{value: string, unit: string, display: string}, height: ?array{value: string, unit: string, display: string}, unitPricingMeasure: ?string, unitPricingBaseMeasure: ?string}
     */
    public function getProductMeasurements(mixed $product): array
    {
        $empty = [
            'weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'unitPricingMeasure' => null,
            'unitPricingBaseMeasure' => null,
        ];

        if (!$product instanceof SalesChannelProductEntity) {
            return $empty;
        }

        return $this->productMeasurementsResolver->resolve($product);
    }
}
