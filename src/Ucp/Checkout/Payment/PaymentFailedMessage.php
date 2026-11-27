<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\Message;

/** @internal */
#[Package('checkout')]
final class PaymentFailedMessage
{
    public static function recoverable(string $content, string $path = '$.payment.instruments[0]'): Message
    {
        return new Message('error', $content, 'recoverable', 'payment_failed', $path);
    }

    public static function ofRefusal(CompletionPaymentException|ValidationException $exception): Message
    {
        // The refusal concerns the payment method behind the handler, not the content of this instrument.
        if ($exception instanceof CompletionPaymentException && CompletionPaymentException::PAYMENT_METHOD_NOT_CHANGEABLE === $exception->getErrorCode()) {
            return self::recoverable($exception->getMessage(), '$.payment.instruments');
        }

        return self::recoverable($exception->getMessage());
    }
}
