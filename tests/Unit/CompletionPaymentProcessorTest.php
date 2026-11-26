<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentProcessor;
use Swag\AgenticCommerce\Ucp\Gateway\OrderPaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(CompletionPaymentProcessor::class)]
final class CompletionPaymentProcessorTest extends TestCase
{
    private const ORDER_ID = '00000000000000000000000000000001';
    private const SALES_CHANNEL_ID = '00000000000000000000000000000002';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000003';
    private const CONTEXT_TOKEN = 'context-token';
    private const FINISH_URL = 'https://shop.example/account/order/deep-link-code';
    private const ERROR_URL = 'https://shop.example/ucp/checkout/continue/checkout-1';
    private const REDIRECT_URL = 'https://psp.example/pay/1';

    private AbstractCompletionPaymentApplier&MockObject $applier;

    private OrderPaymentGatewayInterface&MockObject $paymentGateway;

    private AbstractCartLoadRoute&MockObject $cartLoadRoute;

    private CollectingLogger $logger;

    private CompletionPaymentProcessor $processor;

    protected function setUp(): void
    {
        $this->applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $this->paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $this->cartLoadRoute = $this->createMock(AbstractCartLoadRoute::class);
        $this->logger = new CollectingLogger();
        $this->processor = new CompletionPaymentProcessor($this->applier, $this->paymentGateway, $this->cartLoadRoute, $this->logger);
    }

    #[Test]
    public function testTheSwitchedContextIsReturnedWhenTheTotalStaysTheSame(): void
    {
        $context = $this->salesChannelContext();
        $switchedContext = $this->salesChannelContext();
        $instrument = $this->instrument();
        $requestContext = new RequestContext('shop.example');
        $this->applier->expects(static::once())->method('apply')->with($instrument, $context, $requestContext)->willReturn($switchedContext);
        $this->cartLoadRoute->method('load')->willReturnOnConsecutiveCalls(
            $this->cartResponseWithTotal(10.0),
            $this->cartResponseWithTotal(10.0),
        );

        self::assertSame($switchedContext, $this->processor->apply($instrument, $context, $requestContext));
    }

    #[Test]
    public function testASwitchThatChangesTheTotalIsRefusedWithTheCheckoutLeftToBeUpdated(): void
    {
        $this->applier->method('apply')->willReturn($this->salesChannelContext());
        $this->cartLoadRoute->method('load')->willReturnOnConsecutiveCalls(
            $this->cartResponseWithTotal(10.0),
            $this->cartResponseWithTotal(12.5),
        );

        try {
            $this->processor->apply($this->instrument(), $this->salesChannelContext(), new RequestContext('shop.example'));
            self::fail('A payment method that changes the total must not be applied silently.');
        } catch (CompletionPaymentException $exception) {
            self::assertSame(CompletionPaymentException::PAYMENT_METHOD_CHANGES_TOTAL, $exception->getErrorCode());
            self::assertStringContainsString('Update the checkout with this instrument first', $exception->getMessage());
        }
    }

    #[Test]
    public function testTheTotalIsReadWithTheContextBeforeAndAfterTheSwitch(): void
    {
        $context = $this->salesChannelContext();
        $switchedContext = $this->salesChannelContext();
        $this->applier->method('apply')->willReturn($switchedContext);
        $loadedWith = [];
        $this->cartLoadRoute->method('load')->willReturnCallback(function (Request $request, SalesChannelContext $loadedContext) use (&$loadedWith): CartResponse {
            $loadedWith[] = [$request->query->get('token'), $loadedContext];

            return $this->cartResponseWithTotal(10.0);
        });

        $this->processor->apply($this->instrument(), $context, new RequestContext('shop.example'));

        self::assertSame([[self::CONTEXT_TOKEN, $context], [self::CONTEXT_TOKEN, $switchedContext]], $loadedWith);
    }

    #[Test]
    public function testARefusalOfTheApplierIsNotCaught(): void
    {
        $this->cartLoadRoute->method('load')->willReturn($this->cartResponseWithTotal(10.0));
        $this->applier->method('apply')->willThrowException(CompletionPaymentException::paymentMethodUnavailable('com.example.psp'));

        $this->expectExceptionObject(CompletionPaymentException::paymentMethodUnavailable('com.example.psp'));

        $this->processor->apply($this->instrument(), $this->salesChannelContext(), new RequestContext('shop.example'));
    }

    #[Test]
    public function testAPaymentWithoutRedirectIsCompleted(): void
    {
        $context = $this->salesChannelContext();
        $instrument = $this->instrument();
        $this->paymentGateway->expects(static::once())
            ->method('pay')
            ->with(self::ORDER_ID, $context, $instrument, self::FINISH_URL, self::ERROR_URL)
            ->willReturn(null);

        $result = $this->processor->pay(self::ORDER_ID, $context, $instrument, self::FINISH_URL, self::ERROR_URL);

        self::assertSame(CheckoutStatus::Completed, $result->status);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function testAPaymentWithRedirectAwaitsTheBuyerThere(): void
    {
        $this->paymentGateway->method('pay')->willReturn(self::REDIRECT_URL);

        $result = $this->processor->pay(self::ORDER_ID, $this->salesChannelContext(), $this->instrument(), self::FINISH_URL, self::ERROR_URL);

        self::assertSame(CheckoutStatus::RequiresEscalation, $result->status);
        self::assertSame(self::REDIRECT_URL, $result->redirectUrl);
    }

    #[Test]
    public function testAThrowingPaymentFailsAndIsLoggedWithoutTheCredential(): void
    {
        $exception = new \RuntimeException('Card declined.');
        $this->paymentGateway->method('pay')->willThrowException($exception);

        $result = $this->processor->pay(self::ORDER_ID, $this->salesChannelContext(), $this->instrument(), self::FINISH_URL, self::ERROR_URL);

        self::assertSame(CheckoutStatus::Incomplete, $result->status);
        self::assertTrue($result->retryable);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame([
            'order_id' => self::ORDER_ID,
            'handler_id' => 'com.example.psp',
            'payment_method_id' => self::PAYMENT_METHOD_ID,
            'sales_channel_id' => self::SALES_CHANNEL_ID,
            'exception' => $exception,
        ], $this->logger->records[0]['context']);
        self::assertStringNotContainsString('tok_secret', $this->logger->records[0]['message']);
    }

    #[Test]
    public function testARetryAppliesTheInstrumentThenChangesThePaymentMethodThenPays(): void
    {
        $context = $this->salesChannelContext();
        $appliedContext = $this->salesChannelContext();
        $instrument = $this->instrument();
        $requestContext = new RequestContext('shop.example');
        $calls = [];

        $this->applier->expects(static::once())
            ->method('apply')
            ->with($instrument, $context, $requestContext)
            ->willReturnCallback(static function () use (&$calls, $appliedContext): SalesChannelContext {
                $calls[] = 'apply';

                return $appliedContext;
            });
        $this->paymentGateway->expects(static::once())
            ->method('changePaymentMethod')
            ->with(self::ORDER_ID, $appliedContext)
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'changePaymentMethod';
            });
        $this->paymentGateway->expects(static::once())
            ->method('pay')
            ->with(self::ORDER_ID, $appliedContext, $instrument, self::FINISH_URL, self::ERROR_URL)
            ->willReturnCallback(static function () use (&$calls): ?string {
                $calls[] = 'pay';

                return null;
            });

        $result = $this->processor->retry(self::ORDER_ID, $instrument, $context, $requestContext, self::FINISH_URL, self::ERROR_URL);

        self::assertSame(['apply', 'changePaymentMethod', 'pay'], $calls);
        self::assertSame(CheckoutStatus::Completed, $result->status);
    }

    #[Test]
    public function testARetryWhosePaymentMethodCannotChangeFailsForGoodWithoutPaying(): void
    {
        $exception = new \RuntimeException('The payment of this order can no longer change.');
        $this->applier->method('apply')->willReturnArgument(1);
        $this->paymentGateway->method('changePaymentMethod')->willThrowException($exception);
        $this->paymentGateway->expects(static::never())->method('pay');

        $result = $this->processor->retry(self::ORDER_ID, $this->instrument(), $this->salesChannelContext(), new RequestContext('shop.example'), self::FINISH_URL, self::ERROR_URL);

        self::assertSame(CheckoutStatus::Incomplete, $result->status);
        self::assertFalse($result->retryable);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame([
            'order_id' => self::ORDER_ID,
            'payment_method_id' => self::PAYMENT_METHOD_ID,
            'sales_channel_id' => self::SALES_CHANNEL_ID,
            'exception' => $exception,
        ], $this->logger->records[0]['context']);
    }

    #[Test]
    public function testARetryWhoseInstrumentTheApplierRefusesThrowsWithoutChangingThePayment(): void
    {
        $this->applier->method('apply')->willThrowException(new ValidationException('The instrument is refused.'));
        $this->paymentGateway->expects(static::never())->method('changePaymentMethod');
        $this->paymentGateway->expects(static::never())->method('pay');

        $this->expectExceptionObject(new ValidationException('The instrument is refused.'));

        $this->processor->retry(self::ORDER_ID, $this->instrument(), $this->salesChannelContext(), new RequestContext('shop.example'), self::FINISH_URL, self::ERROR_URL);
    }

    private function cartResponseWithTotal(float $total): CartResponse
    {
        $cart = new Cart(self::CONTEXT_TOKEN);
        $cart->setPrice(new CartPrice($total, $total, $total, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        return new CartResponse($cart);
    }

    private function instrument(): PaymentInstrument
    {
        return new PaymentInstrument('card', 'com.example.psp', ['token' => 'tok_secret']);
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(self::PAYMENT_METHOD_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getToken')->willReturn(self::CONTEXT_TOKEN);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        return $context;
    }
}
