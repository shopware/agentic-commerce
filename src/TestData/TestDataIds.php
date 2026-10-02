<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Every id of the test data set derives from a fixed key, so removal deletes exactly what creation wrote,
 * including rows that carry no marker (variants, prices, downloads, rule conditions).
 *
 * @internal
 */
#[Package('framework')]
final class TestDataIds
{
    public const PRODUCT_NUMBER_PREFIX = 'SWAG-AC-TEST-';
    public const NAME_PREFIX = '[AC Test] ';
    public const MARKER = 'swagAgenticCommerceTestData';

    public static function id(string $key): string
    {
        return Uuid::fromStringToHex('swag-agentic-commerce.test-data.'.$key);
    }

    public static function mediaId(string $role, string $shot): string
    {
        return self::id('media.'.$role.'.'.$shot);
    }

    public static function propertyOptionId(string $group, string $optionKey): string
    {
        return self::id($group.'.'.$optionKey);
    }

    public static function productNumber(string $suffix): string
    {
        return self::PRODUCT_NUMBER_PREFIX.$suffix;
    }

    public static function prefixedName(string $name): string
    {
        return self::NAME_PREFIX.$name;
    }

    public static function prefixedTranslatedName(TranslatedText $name): TranslatedText
    {
        return new TranslatedText(self::prefixedName($name->english), self::prefixedName($name->german));
    }

    /**
     * @return array<string, true>
     */
    public static function markerCustomField(): array
    {
        return [self::MARKER => true];
    }
}
