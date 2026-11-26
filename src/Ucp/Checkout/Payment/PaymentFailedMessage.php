<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Common\Message;

/** @internal */
#[Package('checkout')]
final class PaymentFailedMessage
{
    public static function recoverable(string $content, string $path = '$.payment.instruments[0]'): Message
    {
        return new Message('error', $content, 'recoverable', 'payment_failed', $path);
    }
}
