<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

/**
 * Request-scoped record of AP2 checkout mandates that passed verification, keyed by
 * checkout id. {@see ShopwareAp2CheckoutMandateVerifier} records the raw credential and
 * its verified claims here right before the SDK executor hands the request to checkout
 * completion, so the completer can persist the mandate on the placed order as dispute
 * evidence (per AP2, the merchant is one of the roles expected to be able to provide
 * the checkout mandate in a dispute).
 */
final class Ap2VerifiedMandateRegistry
{
    /** @var array<string, array{checkoutMandate: string, claims: array<string, mixed>}> */
    private array $verified = [];

    /**
     * @param array<string, mixed> $claims
     */
    public function record(string $checkoutId, string $checkoutMandate, array $claims): void
    {
        $this->verified[$checkoutId] = ['checkoutMandate' => $checkoutMandate, 'claims' => $claims];
    }

    /**
     * @return array{checkoutMandate: string, claims: array<string, mixed>}|null
     */
    public function forCheckout(string $checkoutId): ?array
    {
        return $this->verified[$checkoutId] ?? null;
    }
}
