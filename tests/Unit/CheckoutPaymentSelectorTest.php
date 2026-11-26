<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CheckoutPaymentSelector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerPaymentMethodResolver;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(CheckoutPaymentSelector::class)]
final class CheckoutPaymentSelectorTest extends TestCase
{
    private const ADVERTISED_HANDLER_ID = 'com.shopware.invoice';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000001';

    private AbstractCompletionPaymentApplier&MockObject $applier;

    private PaymentHandlerInterface&MockObject $paymentHandler;

    private AbstractPaymentMethodRoute&MockObject $paymentMethodRoute;

    protected function setUp(): void
    {
        $this->applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $this->paymentHandler = $this->createMock(PaymentHandlerInterface::class);
        $this->paymentMethodRoute = $this->createMock(AbstractPaymentMethodRoute::class);
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
    public function testAPlacedOrderIsNotSwitchedWhenTheInstrumentIsUsable(): void
    {
        $this->applier->expects(static::never())->method('apply');
        $this->paymentHandler->method('prepareInstrument')->willReturn(['paymentMethodId' => self::PAYMENT_METHOD_ID, 'token' => '']);
        $this->paymentMethodAvailable(true);

        self::assertNull($this->selector()->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        ));
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
        $this->paymentMethodRoute->expects(static::never())->method('load');

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
    public function testAPlacedOrderRefusesAPaymentMethodTheBuyerMayNotUse(): void
    {
        $this->paymentHandler->method('prepareInstrument')->willReturn(['paymentMethodId' => self::PAYMENT_METHOD_ID, 'token' => '']);
        $this->paymentMethodAvailable(false);

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
    public function testAPlacedOrderRefusesAnInstrumentTheHandlerRejects(): void
    {
        $this->paymentHandler->method('prepareInstrument')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));

        $refusal = $this->selector()->refusalForPlacedOrder(
            new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
            $this->createMock(SalesChannelContext::class),
            $this->requestContext(),
        );

        self::assertInstanceOf(Message::class, $refusal);
        self::assertSame('The instrument is not accepted for this buyer.', $refusal->content);
        self::assertSame('payment_failed', $refusal->code);
    }

    /**
     * @return array<string, mixed>
     */
    private function refusalOf(SalesChannelContext|Message $refusal): array
    {
        self::assertInstanceOf(Message::class, $refusal);

        return $refusal->toArray();
    }

    private function selector(): CheckoutPaymentSelector
    {
        $profileBuilder = RecordingProfileBuilder::advertising(self::ADVERTISED_HANDLER_ID);
        $handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $handlerRegistry->method('find')->with(self::ADVERTISED_HANDLER_ID)->willReturn($this->paymentHandler);

        return new CheckoutPaymentSelector(
            new CompletionPaymentGate(new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder)),
            $this->applier,
            new RegisteredHandlerPaymentMethodResolver(
                $handlerRegistry,
                new SalesChannelPaymentMethods($this->createMock(EntityRepository::class), $this->paymentMethodRoute),
            ),
        );
    }

    private function paymentMethodAvailable(bool $isAvailable): void
    {
        $this->paymentMethodRoute->method('load')->willReturnCallback(
            static function (Request $request, SalesChannelContext $context, Criteria $criteria) use ($isAvailable): PaymentMethodRouteResponse {
                $entities = new PaymentMethodCollection();
                if ($isAvailable) {
                    $paymentMethod = new PaymentMethodEntity();
                    $paymentMethod->setId(self::PAYMENT_METHOD_ID);
                    $entities->add($paymentMethod);
                }

                return new PaymentMethodRouteResponse(new EntitySearchResult('payment_method', $entities->count(), $entities, null, $criteria, Context::createDefaultContext()));
            },
        );
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'));
    }
}
