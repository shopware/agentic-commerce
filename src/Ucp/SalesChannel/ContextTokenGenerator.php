<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Uuid\Uuid;

/** @internal */
final class ContextTokenGenerator
{
    public function generate(): string
    {
        return Uuid::randomHex();
    }
}
