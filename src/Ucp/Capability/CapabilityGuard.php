<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class CapabilityGuard
{
    public static function assertEnabled(RequestContext $context, string $descriptorName, string $message): void
    {
        if (UcpCapabilityCatalog::isEnabled($context->runtimeConfiguration, $descriptorName)) {
            return;
        }

        throw new UnsupportedCapabilityException($message);
    }
}
