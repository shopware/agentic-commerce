<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

final class CheckoutCompletionReservation
{
    private function __construct(
        public readonly CheckoutCompletionReservationStatus $status,
        public readonly ?string $orderId = null,
    ) {
    }

    public static function acquired(): self
    {
        return new self(CheckoutCompletionReservationStatus::Acquired);
    }

    public static function processing(): self
    {
        return new self(CheckoutCompletionReservationStatus::Processing);
    }

    public static function completed(string $orderId): self
    {
        return new self(CheckoutCompletionReservationStatus::Completed, $orderId);
    }
}
