<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * @phpstan-type DeliveryTime array{min: int, max: int, unit: string}
 * @phpstan-type Unit array{code: string, purchase: float, reference: float}
 * @phpstan-type PurchaseLimits array{min: int, max: int, steps: int}
 *
 * @phpstan-import-type CustomFieldValue from ShopLanguages
 *
 * @internal
 */
#[Package('framework')]
final class CatalogueProduct
{
    public const TYPE_PHYSICAL = 'physical';
    public const TYPE_DIGITAL = 'digital';
    public const TAX_STANDARD = 'standard';
    public const TAX_REDUCED = 'reduced';

    /**
     * @param ?DeliveryTime                   $deliveryTime
     * @param ?Unit                           $unit
     * @param ?PurchaseLimits                 $purchaseLimits
     * @param array<string, list<string>>     $optionKeysByGroup properties that are not variant options
     * @param array<string, CustomFieldValue> $customFieldByName keyed without the custom field set prefix
     * @param list<string>                    $crossSellingIds   ids from the catalogue, not Shopware ids
     * @param list<CatalogueImage>            $images
     * @param list<CatalogueVariantOption>    $variantOptions
     * @param string                          $downloadMediaKey  always a built-in file, the catalogue ships no downloads
     * @param list<string>                    $categoryKeys      the first is the main category
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly TranslatedText $name,
        public readonly float $price,
        public readonly int $stock,
        public readonly ?TranslatedText $description = null,
        public readonly ?TranslatedText $keywords = null,
        public readonly ?TranslatedText $metaTitle = null,
        public readonly ?TranslatedText $metaDescription = null,
        public readonly ?string $manufacturer = null,
        public readonly ?string $gtin = null,
        public readonly ?float $listPrice = null,
        public readonly string $taxClass = self::TAX_STANDARD,
        public readonly ?float $weight = null,
        public readonly ?float $width = null,
        public readonly ?float $height = null,
        public readonly ?float $length = null,
        public readonly ?array $deliveryTime = null,
        public readonly ?array $unit = null,
        public readonly ?array $purchaseLimits = null,
        public readonly array $optionKeysByGroup = [],
        public readonly array $customFieldByName = [],
        public readonly array $crossSellingIds = [],
        public readonly array $images = [],
        public readonly ?string $variantPropertyGroup = null,
        public readonly array $variantOptions = [],
        public readonly string $downloadMediaKey = FoundationSeeder::MEDIA_GUIDE,
        public readonly array $categoryKeys = [],
    ) {
    }

    public static function fromJsonObject(CatalogueJsonObject $productJson): self
    {
        $variantsJson = $productJson->optionalObject('variants');
        $unit = $productJson->optionalObject('unit');
        $purchaseLimits = $productJson->optionalObject('purchase');

        $optionKeysByGroup = [];
        if ($productJson->has('properties')) {
            $propertiesJson = $productJson->requireObject('properties');
            foreach (array_keys($propertiesJson->toArray()) as $group) {
                $optionKeysByGroup[(string) $group] = $propertiesJson->optionalStringList((string) $group);
            }
        }

        return new self(
            $productJson->requireString('id'),
            self::validType($productJson->requireString('type'), $productJson->jsonPath()),
            $productJson->requireTranslatedText('name'),
            $productJson->requireNumber('price'),
            $productJson->requireInteger('stock'),
            $productJson->optionalTranslatedText('description'),
            $productJson->optionalTranslatedText('keywords'),
            $productJson->optionalTranslatedText('metaTitle'),
            $productJson->optionalTranslatedText('metaDescription'),
            $productJson->optionalString('manufacturer'),
            $productJson->optionalString('gtin'),
            $productJson->optionalNumber('listPrice'),
            self::validTaxClass($productJson->optionalString('tax') ?? self::TAX_STANDARD, $productJson->jsonPath()),
            $productJson->optionalNumber('weight'),
            $productJson->optionalNumber('width'),
            $productJson->optionalNumber('height'),
            $productJson->optionalNumber('length'),
            self::deliveryTimeOf($productJson->optionalObject('deliveryTime')),
            null === $unit ? null : ['code' => $unit->requireString('code'), 'purchase' => $unit->requireNumber('purchase'), 'reference' => $unit->requireNumber('reference')],
            null === $purchaseLimits ? null : ['min' => $purchaseLimits->requireInteger('min'), 'max' => $purchaseLimits->requireInteger('max'), 'steps' => $purchaseLimits->requireInteger('steps')],
            $optionKeysByGroup,
            $productJson->has('customFields') ? self::customFieldsFrom($productJson->requireObject('customFields')) : [],
            $productJson->optionalStringList('crossSelling'),
            array_values(array_map(CatalogueImage::fromJsonObject(...), $productJson->objectsByKey('images'))),
            $variantsJson?->requireString('group'),
            null === $variantsJson ? [] : array_values(array_map(CatalogueVariantOption::fromJsonObject(...), $variantsJson->objectsByKey('options'))),
            FoundationSeeder::MEDIA_GUIDE,
            $productJson->optionalStringList('categories'),
        );
    }

    public static function validType(string $type, string $jsonPath): string
    {
        if (self::TYPE_PHYSICAL !== $type && self::TYPE_DIGITAL !== $type) {
            throw TestDataException::invalidCatalogue($jsonPath.'.type', 'physical or digital');
        }

        return $type;
    }

    /**
     * @return ?DeliveryTime
     */
    public static function deliveryTimeOf(?CatalogueJsonObject $deliveryTimeJson): ?array
    {
        return null === $deliveryTimeJson ? null : ['min' => $deliveryTimeJson->requireInteger('min'), 'max' => $deliveryTimeJson->requireInteger('max'), 'unit' => $deliveryTimeJson->requireString('unit')];
    }

    public function isDigital(): bool
    {
        return self::TYPE_DIGITAL === $this->type;
    }

    public function hasDigitalOption(): bool
    {
        return [] !== array_filter($this->variantOptions, static fn (CatalogueVariantOption $option): bool => $option->isDigital());
    }

    public function hasPhysicalOption(): bool
    {
        return [] !== array_filter($this->variantOptions, static fn (CatalogueVariantOption $option): bool => !$option->isDigital());
    }

    public function imageWithRole(string $role): ?CatalogueImage
    {
        foreach ($this->images as $image) {
            if ($role === $image->role) {
                return $image;
            }
        }

        return null;
    }

    private static function validTaxClass(string $taxClass, string $jsonPath): string
    {
        if (self::TAX_STANDARD !== $taxClass && self::TAX_REDUCED !== $taxClass) {
            throw TestDataException::invalidCatalogue($jsonPath.'.tax', 'standard or reduced');
        }

        return $taxClass;
    }

    /**
     * @return array<string, CustomFieldValue>
     */
    private static function customFieldsFrom(CatalogueJsonObject $customFieldsJson): array
    {
        $customFieldByName = [];
        foreach ($customFieldsJson->toArray() as $name => $value) {
            $name = (string) $name;
            $customFieldByName[$name] = \is_array($value) ? $customFieldsJson->requireTranslatedText($name) : $value;
            if (!\is_array($value) && !\is_scalar($value)) {
                throw TestDataException::invalidCatalogue($customFieldsJson->jsonPath().'.'.$name, 'a scalar or texts keyed by language');
            }
        }

        return $customFieldByName;
    }
}
