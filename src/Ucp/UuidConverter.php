<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp;

use Shopware\Core\Framework\Log\Package;

/**
 * Converts Shopware hex UUIDs to their binary representation for DBAL queries.
 *
 * Abstracted behind an interface so the DBAL repositories that use it remain
 * unit-testable without shopware/core on the classpath.
 */
#[Package('framework')]
interface UuidConverter
{
    /**
     * @throws \InvalidArgumentException when $hex is not a valid 32-char hex UUID
     */
    public function fromHexToBytes(string $hex): string;
}
