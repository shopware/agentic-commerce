<?php

declare(strict_types=1);

namespace Shopware\Core\Test;

/**
 * Cross-version stub so PHPStan accepts both the 6.5 and 6.6+ method names.
 * createSalesChannelContext() was removed in 6.7.0; generateSalesChannelContext() did not
 * exist in 6.5. Native types are left as mixed to avoid stub-validation failures when
 * PHPStan runs without Shopware's own PHPStan configuration loaded (CI --no-dev install).
 */
class Generator
{
    public static function generateSalesChannelContext(
        mixed $baseContext = null,
        mixed $salesChannel = null,
        mixed $country = null,
    ): mixed {
    }

    public static function createSalesChannelContext(
        mixed $baseContext = null,
        mixed $salesChannel = null,
        mixed $country = null,
    ): mixed {
    }
}
