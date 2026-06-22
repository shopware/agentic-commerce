<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Order\OrderEntity;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Order\OrderView;

interface ShopwareDataMapperInterface
{
    public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null): Checkout;

    public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null): OrderView;
}
