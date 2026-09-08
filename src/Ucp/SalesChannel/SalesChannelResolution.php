<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

/** @internal */
final class SalesChannelResolution
{
    public function __construct(
        public readonly string $salesChannelId,
        public readonly string $baseUrl,
        public readonly ?string $domainId = null,
        public readonly ?string $languageId = null,
        public readonly ?string $currencyId = null,
    ) {
    }
}
