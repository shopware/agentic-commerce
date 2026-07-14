<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CheckoutMerchantAuthorizationSignerInterface;

/**
 * Adds `ap2.merchant_authorization` to checkout responses when the agent negotiated
 * the AP2 mandate capability: a detached JWS over the canonical checkout payload
 * excluding its top-level `ap2` member.
 */
final class Ap2MerchantAuthorizationResponseAugmenter implements CheckoutResponseAugmenterInterface
{
    public function __construct(
        private readonly CheckoutMerchantAuthorizationSignerInterface $signer,
    ) {
    }

    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        if (!\in_array(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE, $context->negotiation?->capabilityNames() ?? [], true)) {
            return $checkout;
        }

        try {
            $merchantAuthorization = $this->signer->sign($checkout->toArray(), $context);
        } catch (SignatureException) {
            // Without an active signing key the response stays unsigned; completion
            // enforcement does not depend on this signature.
            return $checkout;
        }

        $ap2 = \is_array($checkout->extra['ap2'] ?? null) ? $checkout->extra['ap2'] : [];
        $ap2['merchant_authorization'] = $merchantAuthorization;

        return new Checkout(
            $checkout->id,
            $checkout->status,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            $checkout->order,
            array_merge($checkout->extra, ['ap2' => $ap2]),
        );
    }
}
