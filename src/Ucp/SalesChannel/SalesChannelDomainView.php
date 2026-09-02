<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
final class SalesChannelDomainView implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $languageId,
        public readonly ?string $currencyId,
    ) {
    }

    /**
     * @return array{id: string, url: string, languageId: string, currencyId: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'languageId' => $this->languageId,
            'currencyId' => $this->currencyId,
        ];
    }
}
