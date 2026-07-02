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
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
