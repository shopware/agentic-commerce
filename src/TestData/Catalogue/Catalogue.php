<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * The parsed `catalogue.json`, schema 1.
 *
 * @see docs/manual-testing.md
 *
 * @phpstan-type PropertyGroup array{name: TranslatedText, display: string, options: array<string, TranslatedText>}
 * @phpstan-type Manufacturer array{name: string, description: ?TranslatedText}
 *
 * @internal
 */
#[Package('framework')]
final class Catalogue
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<CatalogueProduct>        $products
     * @param array<string, PropertyGroup>  $propertyGroupByKey
     * @param array<string, Manufacturer>   $manufacturerByKey
     * @param array<string, TranslatedText> $categoryNameByKey  in the order the category tree shows them
     * @param ?string                       $version            null for the built-in products
     */
    public function __construct(
        public readonly array $products,
        public readonly array $propertyGroupByKey,
        public readonly array $manufacturerByKey = [],
        public readonly array $categoryNameByKey = [],
        public readonly ?string $version = null,
    ) {
        foreach ($products as $product) {
            foreach ($product->categoryKeys as $categoryKey) {
                if (!isset($categoryNameByKey[$categoryKey])) {
                    throw TestDataException::invalidCatalogue('products.'.$product->id.'.categories', 'keys of the catalogue categories');
                }
            }
            foreach ($product->optionKeysByGroup as $groupKey => $optionKeys) {
                foreach ($optionKeys as $optionKey) {
                    if (!isset($propertyGroupByKey[$groupKey]['options'][$optionKey])) {
                        throw TestDataException::invalidCatalogue('products.'.$product->id.'.properties.'.$groupKey, 'options of the catalogue property group');
                    }
                }
            }
            if (null !== $product->variantPropertyGroup && !isset($propertyGroupByKey[$product->variantPropertyGroup])) {
                throw TestDataException::invalidCatalogue('products.'.$product->id.'.variants.group', 'a key of the catalogue property groups');
            }
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $catalogueFields = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw TestDataException::invalidCatalogue('catalogue.json', 'valid JSON: '.$exception->getMessage());
        }
        if (!\is_array($catalogueFields)) {
            throw TestDataException::invalidCatalogue('catalogue.json', 'an object');
        }

        $root = new CatalogueJsonObject($catalogueFields, 'catalogue');
        if (self::SCHEMA_VERSION !== $root->requireInteger('schema')) {
            throw TestDataException::unsupportedCatalogueSchema($root->requireInteger('schema'));
        }

        $propertyGroupByKey = [];
        foreach ($root->objectsByKey('propertyGroups') as $key => $group) {
            $propertyGroupByKey[$key] = [
                'name' => $group->requireTranslatedText('name'),
                'display' => $group->requireString('display'),
                'options' => array_map(static fn (CatalogueJsonObject $option): TranslatedText => $option->requireTranslatedText('name'), $group->has('options') ? $group->objectsByKey('options') : []),
            ];
        }

        $manufacturerByKey = array_map(static fn (CatalogueJsonObject $manufacturer): array => [
            'name' => $manufacturer->requireString('name'),
            'description' => $manufacturer->optionalTranslatedText('description'),
        ], $root->objectsByKey('manufacturers'));

        return new self(
            array_values(array_map(CatalogueProduct::fromJsonObject(...), $root->objectsByKey('products'))),
            $propertyGroupByKey,
            $manufacturerByKey,
            array_map(static fn (CatalogueJsonObject $category): TranslatedText => $category->requireTranslatedText('name'), $root->objectsByKey('categories')),
            $root->requireString('version'),
        );
    }

    /**
     * @return list<string> every image path the products reference, the only files the archive may be read for
     */
    public function imageFiles(): array
    {
        $files = [];
        foreach ($this->products as $product) {
            foreach ($product->images as $image) {
                $files[] = $image->file;
            }
        }

        return $files;
    }
}
