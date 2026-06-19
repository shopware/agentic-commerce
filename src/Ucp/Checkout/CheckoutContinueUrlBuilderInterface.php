<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

interface CheckoutContinueUrlBuilderInterface
{
    public function build(string $checkoutId, string $salesChannelId): ?string;
}
