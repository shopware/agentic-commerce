<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

enum CheckoutCompletionReservationStatus: string
{
    case Acquired = 'acquired';
    case Processing = 'processing';
    case Completed = 'completed';
}
