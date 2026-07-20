<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Ucp\Sdk\Contract\Ap2CheckoutMandateVerifierInterface;
use Ucp\Sdk\Exception\Ap2Exception;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\RequestContext;

/**
 * Enforces AP2 checkout mandates at completion time: AP2-locked checkouts must carry
 * a mandate, and any provided mandate must cryptographically verify and cover the
 * current Shopware checkout terms.
 */
final class ShopwareAp2CheckoutMandateVerifier implements Ap2CheckoutMandateVerifierInterface
{
    /**
     * @param iterable<Ap2MandateClaimsVerifierInterface> $claimsVerifiers
     */
    public function __construct(
        private readonly Ap2CheckoutLockReaderInterface $lockReader,
        private readonly ShopwareCheckoutTermsFactory $termsFactory,
        private readonly iterable $claimsVerifiers = [],
        private readonly ?Ap2VerifiedMandateRegistry $mandateRegistry = null,
    ) {
    }

    public function verify(CheckoutCompleteRequest $request, Checkout $currentCheckout, RequestContext $context): void
    {
        $mandate = $request->ap2?->checkoutMandate;
        $hasMandate = \is_string($mandate) && '' !== $mandate;

        if (!$hasMandate) {
            if ($this->lockReader->isLocked($request->id, $context)) {
                throw new Ap2Exception('mandate_required', 'AP2 checkout mandate is required.');
            }

            return;
        }

        $claims = $this->verifiedClaims($mandate, $context);

        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] < time()) {
            throw new Ap2Exception('mandate_expired', 'AP2 checkout mandate is expired.');
        }

        // A mandate that pins no amount authorizes nothing: the total claim is
        // mandatory, and its currency must be pinned either top-level or inside
        // the total claim.
        $total = $claims['total'] ?? null;
        $pinsCurrency = isset($claims['currency']) || (\is_array($total) && isset($total['currency']));
        if (null === $total || !$pinsCurrency) {
            throw new Ap2Exception('mandate_invalid', 'AP2 checkout mandate must claim the authorized total and currency.');
        }

        if (!$this->coversCurrentTerms($claims, $this->termsFactory->terms($currentCheckout))) {
            throw new Ap2Exception('mandate_scope_mismatch', 'AP2 checkout mandate does not match current checkout terms.');
        }

        $this->mandateRegistry?->record($request->id, $mandate, $claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $mandate, RequestContext $context): array
    {
        $failure = null;
        foreach ($this->claimsVerifiers as $claimsVerifier) {
            $result = $claimsVerifier->verify($mandate, $context);
            if ($result->verified) {
                return $result->claims;
            }

            $failure ??= $result;
        }

        if (!$failure instanceof Ap2VerificationResult) {
            throw new Ap2Exception('mandate_unsupported', 'No AP2 mandate verifier is available for this shop.');
        }

        throw new Ap2Exception($failure->errorCode ?? 'mandate_verification_failed', $failure->failureReason ?? 'AP2 checkout mandate could not be verified.');
    }

    /**
     * @param array<string, mixed>                                                                                                                                                     $claims
     * @param array{checkout_id: string, currency: string, line_items: list<array{id: string, quantity: int, unit_price: int}>, totals: array{total: int, tax: int, fulfillment: int}} $terms
     */
    private function coversCurrentTerms(array $claims, array $terms): bool
    {
        if (($claims['checkout_id'] ?? null) !== $terms['checkout_id']) {
            return false;
        }

        if (isset($claims['currency']) && $claims['currency'] !== $terms['currency']) {
            return false;
        }

        if (isset($claims['total'])) {
            $total = $claims['total'];
            $amount = \is_array($total) ? ($total['amount'] ?? null) : $total;
            if (!is_numeric($amount) || (int) $amount !== $terms['totals']['total']) {
                return false;
            }

            if (\is_array($total) && isset($total['currency']) && $total['currency'] !== $terms['currency']) {
                return false;
            }
        }

        if (isset($claims['line_items'])) {
            if (!\is_array($claims['line_items'])) {
                return false;
            }

            $currentItems = [];
            $currentUnitPrices = [];
            foreach ($terms['line_items'] as $item) {
                $currentItems[$item['id']] = $item['quantity'];
                $currentUnitPrices[$item['id']] = $item['unit_price'];
            }

            $claimedItems = [];
            foreach ($claims['line_items'] as $row) {
                if (!\is_array($row) || !isset($row['id'])) {
                    return false;
                }

                $id = (string) $row['id'];
                $claimedItems[$id] = (int) ($row['quantity'] ?? 1);

                if (isset($row['unit_price'])) {
                    $unitPrice = $row['unit_price'];
                    $amount = \is_array($unitPrice) ? ($unitPrice['amount'] ?? null) : $unitPrice;
                    if (!is_numeric($amount) || !isset($currentUnitPrices[$id]) || (int) $amount !== $currentUnitPrices[$id]) {
                        return false;
                    }
                }
            }

            ksort($currentItems);
            ksort($claimedItems);

            if ($claimedItems !== $currentItems) {
                return false;
            }
        }

        if (isset($claims['fulfillment_total'])) {
            $fulfillment = $claims['fulfillment_total'];
            $amount = \is_array($fulfillment) ? ($fulfillment['amount'] ?? null) : $fulfillment;
            if (!is_numeric($amount) || (int) $amount !== $terms['totals']['fulfillment']) {
                return false;
            }
        }

        return true;
    }
}
