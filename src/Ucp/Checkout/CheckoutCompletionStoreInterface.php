<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

interface CheckoutCompletionStoreInterface
{
    public function reserve(string $checkoutId, string $salesChannelId): CheckoutCompletionReservation;

    public function complete(string $checkoutId, string $salesChannelId, string $orderId): void;

    public function release(string $checkoutId, string $salesChannelId): void;

    public function completedOrderId(string $checkoutId, string $salesChannelId): ?string;
}
