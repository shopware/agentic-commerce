<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Order\OrderView;

/** @internal */
interface ShopwareDataMapperInterface
{
    /**
     * @param list<Link>                   $legalLinks
     * @param CompletionPaymentResult|null $payment    the state of the order's payment, null for a paid order
     * @param list<Message>|null           $messages   replaces the messages of the payment state
     */
    public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = [], ?CompletionPaymentResult $payment = null, ?array $messages = null): Checkout;

    public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView;
}
