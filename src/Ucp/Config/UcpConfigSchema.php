<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
final class UcpConfigSchema
{
    public const TABLE = 'swag_agentic_commerce_ucp_config';

    private function __construct()
    {
    }
}
