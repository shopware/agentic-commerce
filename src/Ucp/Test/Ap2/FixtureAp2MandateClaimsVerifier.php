<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Test\Ap2;

use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Ucp\Sdk\Model\Ap2\Ap2VerificationResult;
use Ucp\Sdk\Model\RequestContext;

/**
 * Deterministic AP2 mandate verifier for smoke and e2e lanes only. Accepts mandates
 * of the form `fixture.<base64url(json claims)>` and returns the embedded claims
 * without cryptographic verification. Never registered in prod; additionally gated
 * by SWAG_AGENTIC_COMMERCE_TEST_AP2 as defense-in-depth.
 */
final class FixtureAp2MandateClaimsVerifier implements Ap2MandateClaimsVerifierInterface
{
    private const PREFIX = 'fixture.';

    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult
    {
        if (!$this->enabled || !str_starts_with($checkoutMandate, self::PREFIX)) {
            return Ap2VerificationResult::failed('mandate_invalid', 'Fixture mandate could not be verified.');
        }

        $decoded = base64_decode(strtr(substr($checkoutMandate, \strlen(self::PREFIX)), '-_', '+/'), true);
        $claims = false !== $decoded ? json_decode($decoded, true) : null;
        if (!\is_array($claims)) {
            return Ap2VerificationResult::failed('mandate_invalid', 'Fixture mandate claims are malformed.');
        }

        return Ap2VerificationResult::verified($claims);
    }
}
