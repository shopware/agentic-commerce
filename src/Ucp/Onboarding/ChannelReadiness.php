<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainView;

/**
 * One row of the readiness page: what this sales channel is, whether it is
 * prepared, and what still stands between it and an agent that can use it.
 *
 * `findings` is empty for a channel that is not prepared yet, because the
 * validator's answer for an inactive channel is only that it is inactive, which
 * the `prepared` flag already says.
 *
 * @internal
 */
#[Package('framework')]
final class ChannelReadiness implements \JsonSerializable
{
    /**
     * @param list<SalesChannelDomainView> $domains
     * @param list<Finding>                $findings
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly array $domains,
        public readonly int $activeProductCount,
        public readonly bool $prepared,
        public readonly array $findings = [],
    ) {
    }

    public function hasDomain(): bool
    {
        return [] !== $this->domains;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domains' => array_map(
                static fn (SalesChannelDomainView $domain): array => $domain->jsonSerialize(),
                $this->domains,
            ),
            'activeProductCount' => $this->activeProductCount,
            'prepared' => $this->prepared,
            'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->findings),
        ];
    }
}
