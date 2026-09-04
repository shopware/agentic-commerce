<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('checkout')]
interface CheckoutCompletionStoreInterface
{
    public function complete(string $checkoutId, string $orderId): void;

    public function completedOrderId(string $checkoutId): ?string;

    public function completedCheckoutId(string $orderId): ?string;
}
