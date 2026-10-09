<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
final class ReadinessSummary
{
    public function __construct(
        public readonly int $preparedSalesChannels,
        public readonly int $agenticSalesChannels,
        public readonly int $transactionalSalesChannels,
    ) {
    }

    /**
     * @return array{preparedSalesChannels: int, agenticSalesChannels: int, transactionalSalesChannels: int}
     */
    public function toArray(): array
    {
        return [
            'preparedSalesChannels' => $this->preparedSalesChannels,
            'agenticSalesChannels' => $this->agenticSalesChannels,
            'transactionalSalesChannels' => $this->transactionalSalesChannels,
        ];
    }
}
