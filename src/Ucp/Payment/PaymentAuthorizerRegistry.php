<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

final class PaymentAuthorizerRegistry
{
    /**
     * @param iterable<PaymentAuthorizerInterface> $authorizers
     */
    public function __construct(
        private readonly iterable $authorizers = [],
    ) {
    }

    public function authorize(
        CheckoutCompleteRequest $request,
        PaymentInstrument $instrument,
        Cart $cart,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): PaymentAuthorizationResult {
        foreach ($this->authorizers as $authorizer) {
            if ($authorizer->supports($instrument->handlerId)) {
                return $authorizer->authorize($request, $instrument, $cart, $context, $requestContext);
            }
        }

        return PaymentAuthorizationResult::failed(
            'payment_handler_unsupported',
            \sprintf('No payment authorizer supports handler "%s".', $instrument->handlerId),
        );
    }
}
