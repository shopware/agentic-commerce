<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;

/**
 * An existing Agentic Commerce feed channel, as the readiness page lists it
 * under the storefront it exports.
 *
 * @internal
 */
#[Package('framework')]
final class FeedChannelView implements \JsonSerializable
{
    public function __construct(
        public readonly FeedProvider $provider,
        public readonly string $salesChannelId,
        public readonly ?string $salesChannelName,
        public readonly ?string $feedUrl,
        public readonly bool $active,
    ) {
    }

    /**
     * @return array{provider: string, salesChannelId: string, salesChannelName: string|null, feedUrl: string|null, active: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider->value,
            'salesChannelId' => $this->salesChannelId,
            'salesChannelName' => $this->salesChannelName,
            'feedUrl' => $this->feedUrl,
            'active' => $this->active,
        ];
    }
}
