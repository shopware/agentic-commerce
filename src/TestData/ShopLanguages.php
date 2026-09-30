<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\Log\Package;

/**
 * A shop whose system language is neither English nor German shows the English texts.
 *
 * @phpstan-type CustomFieldValue TranslatedText|string|int|float|bool
 *
 * @internal
 */
#[Package('framework')]
final class ShopLanguages
{
    public const ENGLISH = 'en';
    public const GERMAN = 'de';

    /**
     * @param array<string, string> $languageIdByLanguage
     */
    public function __construct(
        public readonly string $systemLanguage = self::ENGLISH,
        public readonly array $languageIdByLanguage = [],
    ) {
    }

    /**
     * @param array<string, ?TranslatedText>  $textByField       a field without a text is left out
     * @param array<string, CustomFieldValue> $customFieldByName only a text differs per language
     *
     * @return array<string, mixed>
     */
    public function translatedFields(array $textByField, array $customFieldByName = []): array
    {
        $textByField = array_filter($textByField);
        $payload = self::fieldsIn($this->systemLanguage, $textByField, $customFieldByName);

        $translations = [];
        foreach ($this->languageIdByLanguage as $language => $languageId) {
            $translation = self::fieldsIn($language, $textByField, $customFieldByName);
            if ([] !== $translation) {
                $translations[$languageId] = $translation;
            }
        }
        if ([] !== $translations) {
            $payload['translations'] = $translations;
        }

        return $payload;
    }

    /**
     * @param array<string, TranslatedText>   $textByField
     * @param array<string, CustomFieldValue> $customFieldByName
     *
     * @return array<string, mixed>
     */
    private static function fieldsIn(string $language, array $textByField, array $customFieldByName): array
    {
        $fields = array_map(static fn (TranslatedText $text): string => $text->in($language), $textByField);
        if ([] !== $customFieldByName) {
            $fields['customFields'] = array_map(static fn (TranslatedText|string|int|float|bool $value): string|int|float|bool => $value instanceof TranslatedText ? $value->in($language) : $value, $customFieldByName);
        }

        return $fields;
    }
}
