<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

final readonly class SalesChannelResolution
{
    public function __construct(
        public string $salesChannelId,
        public string $baseUrl,
        public ?string $domainId = null,
        public ?string $languageId = null,
        public ?string $currencyId = null,
    ) {
    }
}
