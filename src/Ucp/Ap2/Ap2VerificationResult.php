<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

/**
 * Result of a cryptographic AP2 mandate claims verification
 * ({@see Ap2MandateClaimsVerifierInterface}). Owned by the plugin: the SDK dropped its
 * equivalent model in favour of exception-based signalling, but the plugin keeps the
 * result object so several registered claims verifiers can be consulted in order
 * without flow control by exception ({@see ShopwareAp2CheckoutMandateVerifier}).
 */
final class Ap2VerificationResult
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly bool $verified,
        public readonly array $claims = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $failureReason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function verified(array $claims): self
    {
        return new self(true, $claims);
    }

    public static function failed(string $errorCode, string $failureReason): self
    {
        return new self(false, [], $errorCode, $failureReason);
    }
}
