<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/** @internal */
interface OrderPaymentGatewayInterface
{
    /**
     * @return string|null the URL the buyer has to open to finish the payment, null when no redirect is needed
     *
     * @throws \Throwable when the payment handler refuses or fails
     */
    public function pay(string $orderId, SalesChannelContext $context, ?PaymentInstrument $instrument, string $returnUrl): ?string;

    /**
     * @throws \Throwable when Shopware does not allow the order's payment to change any more
     */
    public function changePaymentMethod(string $orderId, SalesChannelContext $context): void;
}
