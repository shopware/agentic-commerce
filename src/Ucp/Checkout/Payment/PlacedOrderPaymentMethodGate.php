<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;

/**
 * Refuses a payment method without "Allow payment change after checkout" for a placed order, as far as core does.
 *
 * @internal
 */
#[Package('checkout')]
final class PlacedOrderPaymentMethodGate
{
    public function __construct(private readonly ShopwareVersionDetector $versionDetector)
    {
    }

    /**
     * @param SalesChannelContext $context the context the applier switched to the payment method of the placed order
     *
     * @throws CompletionPaymentException when core would refuse the payment method of the context for a placed order
     */
    public function assertAllowed(SalesChannelContext $context): void
    {
        if ($this->versionDetector->enforcesPaymentChangeAfterOrder() && !$context->getPaymentMethod()->getAfterOrderEnabled()) {
            throw CompletionPaymentException::paymentMethodNotChangeable();
        }
    }
}
