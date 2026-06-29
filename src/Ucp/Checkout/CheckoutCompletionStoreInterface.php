<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

/** @internal */
interface CheckoutCompletionStoreInterface
{
    public function complete(string $checkoutId, string $orderId): void;

    public function completedOrderId(string $checkoutId): ?string;
}
