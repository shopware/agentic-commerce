<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Ucp\Sdk\Model\RequestContext;

/**
 * Verifies the cryptographic integrity of an AP2 checkout mandate (SD-JWT and key
 * binding) and returns its claims. Implemented by AP2/PSP plugins and tagged with
 * `swag_agentic_commerce.ucp.ap2_mandate_claims_verifier` (autoconfigured). The AP2
 * capability is only advertised when at least one implementation is registered.
 */
interface Ap2MandateClaimsVerifierInterface
{
    public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult;
}
