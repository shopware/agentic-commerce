<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final class TranslatedText
{
    public function __construct(
        public readonly string $english,
        public readonly string $german,
    ) {
    }

    public function in(string $language): string
    {
        return ShopLanguages::GERMAN === $language ? $this->german : $this->english;
    }
}
