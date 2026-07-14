<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * Advertises the AP2 mandate capability descriptor in the UCP profile. Whether it is
 * actually exposed is decided by CapabilityFilteringProfileContributor (config opt-in
 * plus a registered AP2 mandate claims verifier); enforcement happens at completion
 * time in ShopwareAp2CheckoutMandateVerifier.
 */
final class Ap2MandateCapability implements CapabilityInterface
{
    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_AP2_MANDATE);
    }
}
