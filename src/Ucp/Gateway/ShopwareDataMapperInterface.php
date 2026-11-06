<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Order\OrderEntity;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Order\OrderView;

/** @internal */
interface ShopwareDataMapperInterface
{
    /**
     * @param list<Link> $legalLinks
     */
    public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = []): Checkout;

    public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView;
}
