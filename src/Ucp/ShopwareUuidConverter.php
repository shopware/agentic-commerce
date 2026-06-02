<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

#[Package('framework')]
final readonly class ShopwareUuidConverter implements UuidConverter
{
    public function fromHexToBytes(string $hex): string
    {
        return Uuid::fromHexToBytes($hex);
    }
}
