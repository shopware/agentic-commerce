<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\Log;

/**
 * Extends the 6.5.x PackageString type alias to include packages added in 6.6.x+.
 * Without this stub, PHPStan would reject #[Package('discovery')] and #[Package('framework')]
 * when analysing against a Shopware 6.5 installation.
 *
 * @phpstan-type PackageString 'stranger-codes'|'buyers-experience'|'services-settings'|'business-ops'|'inventory'|'content'|'system-settings'|'sales-channel'|'customer-order'|'checkout'|'merchant-services'|'storefront'|'core'|'administration'|'innovation'|'data-services'|'discovery'|'framework'
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Package
{
    public const PACKAGE_TRACE_ATTRIBUTE_KEY = 'pTrace';

    /**
     * @param PackageString $package
     */
    public function __construct(public string $package)
    {
    }
}
