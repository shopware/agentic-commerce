<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config\Validation;

use Shopware\Core\Framework\Log\Package;

/**
 * Severity of a {@see Finding} produced by {@see UcpConfigValidator}.
 *
 * @internal
 */
#[Package('framework')]
enum Severity: int
{
    case Info = 0;
    case Warning = 1;
    case Error = 2;

    public function label(): string
    {
        return strtoupper($this->name);
    }
}
