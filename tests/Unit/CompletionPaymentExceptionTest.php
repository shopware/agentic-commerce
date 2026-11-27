<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PaymentFailedMessage;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class CompletionPaymentExceptionTest extends TestCase
{
    #[Test]
    public function testItBuildsPaymentMethodUnavailableException(): void
    {
        $exception = CompletionPaymentException::paymentMethodUnavailable('com.shopware.invoice');

        self::assertSame(CompletionPaymentException::PAYMENT_METHOD_UNAVAILABLE, $exception->getErrorCode());
        self::assertSame('The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.', $exception->getMessage());
        self::assertSame(['handlerId' => 'com.shopware.invoice'], $exception->getParameters());
    }

    #[Test]
    public function testItBuildsHandlerNotRegisteredException(): void
    {
        $exception = CompletionPaymentException::handlerNotRegistered('com.example.unknown');

        self::assertSame(CompletionPaymentException::HANDLER_NOT_REGISTERED, $exception->getErrorCode());
        self::assertSame('No payment handler "com.example.unknown" is registered.', $exception->getMessage());
        self::assertSame(['handlerId' => 'com.example.unknown'], $exception->getParameters());
    }

    #[Test]
    public function testItBuildsHandlerNotRegisteredExceptionForACheckoutWithoutInstrument(): void
    {
        $exception = CompletionPaymentException::handlerNotRegistered(null);

        self::assertSame(CompletionPaymentException::HANDLER_NOT_REGISTERED, $exception->getErrorCode());
        self::assertSame('The checkout carries no payment instrument, so no payment handler applies.', $exception->getMessage());
    }

    #[Test]
    public function testItBuildsPaymentFailedException(): void
    {
        $exception = CompletionPaymentException::paymentFailed('CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED');

        self::assertSame(CompletionPaymentException::PAYMENT_FAILED, $exception->getErrorCode());
        self::assertSame('Shopware answered the payment with its error redirect, error code "CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED".', $exception->getMessage());
        self::assertSame(['errorCode' => 'CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED'], $exception->getParameters());
    }

    #[Test]
    public function testItBuildsPaymentMethodChangesTotalException(): void
    {
        $exception = CompletionPaymentException::paymentMethodChangesTotal();

        self::assertSame(CompletionPaymentException::PAYMENT_METHOD_CHANGES_TOTAL, $exception->getErrorCode());
        self::assertStringContainsString('Update the checkout with this instrument first', $exception->getMessage());
    }

    #[Test]
    public function testItBuildsPaymentMethodNotChangeableException(): void
    {
        $exception = CompletionPaymentException::paymentMethodNotChangeable();

        self::assertSame(CompletionPaymentException::PAYMENT_METHOD_NOT_CHANGEABLE, $exception->getErrorCode());
        self::assertSame('This payment method cannot pay an order that is already placed.', $exception->getMessage());
    }

    #[Test]
    public function testTheMessageOfARefusalIsARecoverablePaymentFailure(): void
    {
        self::assertSame([
            'type' => 'error',
            'content' => 'Refused.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ], PaymentFailedMessage::recoverable('Refused.')->toArray());
        self::assertSame('$.payment.instruments', PaymentFailedMessage::recoverable('Refused.', '$.payment.instruments')->path);
    }

    #[Test]
    public function testTheMessageOfAnExceptionPointsAtTheInstrumentUnlessItsPaymentMethodCannotPayAPlacedOrder(): void
    {
        self::assertSame(
            '$.payment.instruments[0]',
            PaymentFailedMessage::ofRefusal(CompletionPaymentException::paymentMethodUnavailable('com.shopware.invoice'))->path,
        );
        self::assertSame('$.payment.instruments[0]', PaymentFailedMessage::ofRefusal(new ValidationException('Refused.'))->path);
        self::assertSame('Refused.', PaymentFailedMessage::ofRefusal(new ValidationException('Refused.'))->content);
        self::assertSame(
            '$.payment.instruments',
            PaymentFailedMessage::ofRefusal(CompletionPaymentException::paymentMethodNotChangeable())->path,
        );
    }
}
