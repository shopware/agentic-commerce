<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
interface UcpActivationReaderInterface
{
    /**
     * Narrows the given sales-channel ids to the subset that currently has UCP
     * active. Lets callers reason about activation without depending on the full
     * {@see UcpConfig} value object.
     *
     * @param list<string> $salesChannelIds
     *
     * @return list<string>
     */
    public function activeSalesChannelIds(array $salesChannelIds): array;
}
