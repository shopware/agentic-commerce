<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
final class SalesChannelView implements \JsonSerializable
{
    /**
     * @param list<SalesChannelDomainView> $domains
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly string $typeId,
        public readonly bool $transactional,
        public readonly array $domains,
    ) {
    }

    /**
     * @return array{id: string, name: string|null, typeId: string, transactional: bool, domains: list<array{id: string, url: string, languageId: string, currencyId: string|null}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'typeId' => $this->typeId,
            'transactional' => $this->transactional,
            'domains' => array_map(
                static fn (SalesChannelDomainView $domain): array => $domain->jsonSerialize(),
                $this->domains,
            ),
        ];
    }
}
