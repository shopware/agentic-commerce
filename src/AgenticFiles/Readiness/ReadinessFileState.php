<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
enum ReadinessFileState: string
{
    case Missing = 'missing';
    case Created = 'created';
    case Enabled = 'enabled';
    case DisabledByMerchant = 'disabled_by_merchant';
}
