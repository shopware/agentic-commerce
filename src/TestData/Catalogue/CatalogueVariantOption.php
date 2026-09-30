<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * @phpstan-import-type DeliveryTime from CatalogueProduct
 *
 * @internal
 */
#[Package('framework')]
final class CatalogueVariantOption
{
    /**
     * @param ?DeliveryTime        $deliveryTime
     * @param ?string              $coverFile    archive path of the option's own cover; null shows the parent's images
     * @param array<string, float> $dimensions   width, height, length in mm where the option differs from its parent
     */
    public function __construct(
        public readonly string $key,
        public readonly TranslatedText $name,
        public readonly string $type,
        public readonly ?string $colorHexCode,
        public readonly float $priceDelta,
        public readonly int $stock,
        public readonly ?string $gtin = null,
        public readonly ?float $weight = null,
        public readonly ?array $deliveryTime = null,
        public readonly ?string $coverFile = null,
        public readonly array $dimensions = [],
    ) {
    }

    public static function fromJsonObject(CatalogueJsonObject $optionJson): self
    {
        $coverFile = $optionJson->optionalString('image');

        return new self(
            $optionJson->requireString('key'),
            $optionJson->requireTranslatedText('name'),
            CatalogueProduct::validType($optionJson->requireString('type'), $optionJson->jsonPath()),
            $optionJson->optionalString('color'),
            $optionJson->requireNumber('priceDelta'),
            $optionJson->requireInteger('stock'),
            $optionJson->optionalString('gtin'),
            $optionJson->optionalNumber('weight'),
            CatalogueProduct::deliveryTimeOf($optionJson->optionalObject('deliveryTime')),
            null === $coverFile ? null : CatalogueArchive::validImagePath($coverFile),
        );
    }

    public function isDigital(): bool
    {
        return CatalogueProduct::TYPE_DIGITAL === $this->type;
    }
}
