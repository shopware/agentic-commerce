<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('checkout')]
interface CheckoutContinueUrlBuilderInterface
{
    public function build(string $checkoutId, string $salesChannelId): ?string;
}
