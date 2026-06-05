<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Framework\Bundle;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('discovery')]
final class AgenticFilesFallbackBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function getTemplatePriority(): int
    {
        return 1000;
    }
}
