<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
interface UcpActivationWriterInterface
{
    /**
     * Activates UCP for a sales channel with the default capability set and
     * enables its AI files. Used by the "prepare sales channels" flow.
     */
    public function activate(string $salesChannelId): void;
}
