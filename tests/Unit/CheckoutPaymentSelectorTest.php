<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CheckoutPaymentSelector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(CheckoutPaymentSelector::class)]
final class CheckoutPaymentSelectorTest extends TestCase
{
    use BuildsPlacedOrderPaymentMethodGate;

    private const ADVERTISED_HANDLER_ID = 'com.shopware.invoice';
    private const SHOPWARE_VERSION_WITHOUT_PAYMENT_CHANGE_CHECK = '6.7.12.0';
    private const SHOPWARE_VERSION_WITH_PAYMENT_CHANGE_CHECK = '6.7.13.0';

    private AbstractCompletionPaymentApplier&MockObject $applier;

    protected function setUp(): void
    {
        $this->applier = $this->createMock(AbstractCompletionPaymentApplier::class);
    }

    #[Test]
    public function testAnAdvertisedInstrumentIsAppliedToTheContext(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $context = $this->createMock(SalesChannelContext::class);
        $requestContext = $this->requestContext();
        $this->applier->expects(static::once())->method('apply')->with($instrument, $context, $requestContext)->willReturn($context);

        self::assertSame($context, $this->selector()->select($instrument, $context, $requestContext));
    }

    #[Test]
    public function testTheContextTheApplierReturnsIsTheSelectedOne(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $switchedContext = $this->createMock(SalesChannelContext::class);
        $this->applier->method('apply')->willReturn($switchedContext);

        self::assertSame($switchedContext, $this->selector()->select($instrument, $this->createMock(SalesChannelContext::class), $this->requestContext()));
    }

    #[Test]
    public function testAnInstrumentTheProfileDoesNotAdvertiseIsRefusedWithoutApplyingIt(): void
    {
        $this->applier->expects(static::never())->method('apply');

        $refusal = $this->selector()->select(new PaymentInstrument('invoice', 'com.example.deactivated'), $this->createMock(SalesChannelContext::class), $this->requestContext());

        self::assertInstanceOf(Message::class, $refusal);
        self::assertSame('$.payment.instruments[0].handler_id', $refusal->path);
        self::assertSame('payment_failed', $refusal->code);
    }

    #[Test]
    public function testAnInstrumentTheApplierRefusesAnswersTheRefusalAsARecoverablePaymentFailure(): void
    {
        $this->applier->method('apply')->willThrowException(CompletionPaymentException::paymentMethodUnavailable(self::ADVERTISED_HANDLER_ID));

        $refusal = $this->selector()->select(new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID), $this->createMock(SalesChannelContext::class), $this->requestContext());

        self::assertSame([
            'type' => 'error',
            'content' => 'The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ], $this->refusalOf($refusal));
    }

    #[Test]
    public function testTheValidationExceptionOfAReplacementApplierIsAnsweredLikeTheInternalRefusal(): void
    {
        $this->applier->method('apply')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));

        $refusal = $this->selector()->select(new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID), $this->createMock(SalesChannelContext::class), $this->requestContext());

        self::assertSame([
            'type' => 'error',
            'content' => 'The instrument is not accepted for this buyer.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ], $this->refusalOf($refusal));
    }

    #[Test]
    public function testAnyOtherFailureOfTheApplierPropagates(): void
    {
        $this->applier->method('apply')->willThrowException(new \RuntimeException('The applier is broken.'));

        $this->expectExceptionObject(new \RuntimeException('The applier is broken.'));

        $this->selector()->select(new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID), $this->createMock(SalesChannelContext::class), $this->requestContext());
    }

    #[Test]
    public function testTheRefusalOfAMissingInstrumentIsTheGatesRefusal(): void
    {
        self::assertSame('$.payment.instruments', $this->selector()->refusal(null, $this->requestContext())?->path);
        self::assertNull($this->selector()->refusal(new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID), $this->requestContext()));
    }

    #[Test]
    public function testAPlacedOrderIsAcceptedWhenTheApplierAcceptsTheInstrument(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $context = $this->createMock(SalesChannelContext::class);
        $requestContext = $this->requestContext();
        $this->applier->expects(static::once())->method('apply')->with($instrument, $context, $requestContext)->willReturn($context);

        self::assertNull($this->selector()->refusalForPlacedOrder($instrument, $context, $requestContext));
    }

    #[Test]
    public function testAPlacedOrderIsNotPaidAgainWithoutAnInstrument(): void
    {
        $refusal = $this->selector()->refusalForPlacedOrder(null, $this->createMock(SalesChannelContext::class), $this->requestContext());

        self::assertSame([
            'type' => 'error',
            'content' => 'Send the payment instrument to pay the order again.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAPlacedOrderRefusesAnInstrumentTheProfileDoesNotAdvertise(): void
    {
        $this->applier->expects(static::never())->method('apply');

        $refusal = $this->selector()->refusalForPlacedOrder(
            new PaymentInstrument('invoice', 'com.example.deactivated'),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        );

        self::assertInstanceOf(Message::class, $refusal);
        self::assertSame('$.payment.instruments[0].handler_id', $refusal->path);
        self::assertSame('payment_failed', $refusal->code);
    }

    #[Test]
    public function testAPlacedOrderRefusesAnInstrumentTheApplierRefuses(): void
    {
        $this->applier->method('apply')->willThrowException(CompletionPaymentException::paymentMethodUnavailable(self::ADVERTISED_HANDLER_ID));

        $refusal = $this->selector()->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        );

        self::assertSame([
            'type' => 'error',
            'content' => 'The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAPlacedOrderRefusesAnInstrumentTheApplierRejects(): void
    {
        $this->applier->method('apply')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));

        $refusal = $this->selector()->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        );

        self::assertInstanceOf(Message::class, $refusal);
        self::assertSame('The instrument is not accepted for this buyer.', $refusal->content);
        self::assertSame('payment_failed', $refusal->code);
    }

    #[Test]
    public function testAPlacedOrderRefusesAPaymentMethodWithoutPaymentChangeAfterCheckoutWhereCoreEnforcesIt(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $this->applier->method('apply')->willReturn($this->contextWithAfterOrderEnabled(false));

        $refusal = $this->selector(self::SHOPWARE_VERSION_WITH_PAYMENT_CHANGE_CHECK)->refusalForPlacedOrder($instrument, $this->createMock(SalesChannelContext::class), $this->requestContext());

        self::assertSame([
            'type' => 'error',
            'content' => 'This payment method cannot pay an order that is already placed.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAPlacedOrderRefusesUnrecoverablyWhenNoAdvertisedHandlerHasPaymentChangeAfterCheckout(): void
    {
        $this->applier->method('apply')->willReturn($this->contextWithAfterOrderEnabled(false));

        $refusal = $this->selector(self::SHOPWARE_VERSION_WITH_PAYMENT_CHANGE_CHECK, canBePaidAgain: false)->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        );

        self::assertInstanceOf(Message::class, $refusal);
        self::assertSame('unrecoverable', $refusal->severity);
        self::assertSame('payment_failed', $refusal->code);
        self::assertSame('$.payment.instruments', $refusal->path);
    }

    #[Test]
    public function testAPlacedOrderAcceptsAPaymentMethodWithPaymentChangeAfterCheckoutWhereCoreEnforcesTheCheck(): void
    {
        $this->applier->method('apply')->willReturn($this->contextWithAfterOrderEnabled(true));

        self::assertNull($this->selector(self::SHOPWARE_VERSION_WITH_PAYMENT_CHANGE_CHECK)->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        ));
    }

    #[Test]
    public function testAPlacedOrderAcceptsAPaymentMethodWithoutPaymentChangeAfterCheckoutWhereCoreDoesNotEnforceIt(): void
    {
        $this->applier->method('apply')->willReturn($this->contextWithAfterOrderEnabled(false));

        self::assertNull($this->selector(self::SHOPWARE_VERSION_WITHOUT_PAYMENT_CHANGE_CHECK)->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        ));
    }

    #[Test]
    public function testAnOpenCheckoutSelectsAPaymentMethodWithoutPaymentChangeAfterCheckoutWhereCoreEnforcesTheCheck(): void
    {
        $switchedContext = $this->contextWithAfterOrderEnabled(false);
        $this->applier->method('apply')->willReturn($switchedContext);

        self::assertSame($switchedContext, $this->selector(self::SHOPWARE_VERSION_WITH_PAYMENT_CHANGE_CHECK)->select(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function refusalOf(SalesChannelContext|Message $refusal): array
    {
        self::assertInstanceOf(Message::class, $refusal);

        return $refusal->toArray();
    }

    private function selector(string $shopwareVersion = self::SHOPWARE_VERSION_WITHOUT_PAYMENT_CHANGE_CHECK, bool $canBePaidAgain = true): CheckoutPaymentSelector
    {
        $profileBuilder = RecordingProfileBuilder::advertising(self::ADVERTISED_HANDLER_ID);

        return new CheckoutPaymentSelector(
            new CompletionPaymentGate(new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder)),
            $this->applier,
            $this->placedOrderGateOnShopware($shopwareVersion, $canBePaidAgain),
        );
    }

    private function contextWithAfterOrderEnabled(bool $afterOrderEnabled): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setAfterOrderEnabled($afterOrderEnabled);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        return $context;
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'));
    }
}
