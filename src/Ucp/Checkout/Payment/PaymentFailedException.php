<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

/** @internal */
final class PaymentFailedException extends \RuntimeException
{
    public function __construct(string $errorUrl)
    {
        parent::__construct(\sprintf('Shopware answered the payment with its error redirect "%s".', $errorUrl));
    }
}
