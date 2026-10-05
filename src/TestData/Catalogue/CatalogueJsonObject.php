<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * A value of the wrong type fails with its JSON path, e.g. `products.3.stock`.
 *
 * @internal
 */
#[Package('framework')]
final class CatalogueJsonObject
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private readonly array $data, private readonly string $jsonPath)
    {
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data) && null !== $this->data[$key];
    }

    public function requireString(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'a non-empty string');
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        return $this->has($key) ? $this->requireString($key) : null;
    }

    public function requireNumber(string $key): float
    {
        $value = $this->data[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'a number');
        }

        return (float) $value;
    }

    public function optionalNumber(string $key): ?float
    {
        return $this->has($key) ? $this->requireNumber($key) : null;
    }

    public function requireInteger(string $key): int
    {
        $value = $this->data[$key] ?? null;
        if (!\is_int($value)) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'a whole number');
        }

        return $value;
    }

    public function requireTranslatedText(string $key): TranslatedText
    {
        $translatedText = $this->requireObject($key);
        $languages = array_keys($translatedText->data);
        sort($languages);
        if ([ShopLanguages::GERMAN, ShopLanguages::ENGLISH] !== $languages) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'an object with exactly the texts en and de');
        }

        return new TranslatedText($translatedText->requireString(ShopLanguages::ENGLISH), $translatedText->requireString(ShopLanguages::GERMAN));
    }

    public function optionalTranslatedText(string $key): ?TranslatedText
    {
        return $this->has($key) ? $this->requireTranslatedText($key) : null;
    }

    public function requireObject(string $key): self
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value)) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'an object');
        }

        return new self($value, $this->jsonPath.'.'.$key);
    }

    public function optionalObject(string $key): ?self
    {
        return $this->has($key) ? $this->requireObject($key) : null;
    }

    /**
     * @return array<string, self>
     */
    public function objectsByKey(string $key): array
    {
        $children = [];
        foreach ($this->requireObject($key)->data as $childKey => $value) {
            if (!\is_array($value)) {
                throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key.'.'.$childKey, 'an object');
            }
            $children[(string) $childKey] = new self($value, $this->jsonPath.'.'.$key.'.'.$childKey);
        }

        return $children;
    }

    /**
     * @return list<string>
     */
    public function optionalStringList(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $item): bool => !\is_string($item))) {
            throw TestDataException::invalidCatalogue($this->jsonPath.'.'.$key, 'a list of strings');
        }

        /* @var list<string> $value */
        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonPath(): string
    {
        return $this->jsonPath;
    }
}
