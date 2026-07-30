<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * PSP-facing authorization boundary invoked before UCP order placement.
 *
 * PSP plugins (for example an x402 integration) implement this interface and tag the
 * service with `swag_agentic_commerce.ucp.payment_authorizer` (autoconfigured) in
 * addition to their `ucp_sdk.payment_handler`.
 */
interface PaymentAuthorizerInterface
{
    public function supports(string $handlerId): bool;

    public function authorize(
        CheckoutCompleteRequest $request,
        PaymentInstrument $instrument,
        Cart $cart,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): PaymentAuthorizationResult;
}
