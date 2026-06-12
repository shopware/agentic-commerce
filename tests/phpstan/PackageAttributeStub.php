<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\Log;

// Superseded by Package.php stub; kept to avoid breaking git history.
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PackageAttributeStub
{
    public const PACKAGE_TRACE_ATTRIBUTE_KEY = 'pTrace';

    public function __construct(public string $package)
    {
    }
}
