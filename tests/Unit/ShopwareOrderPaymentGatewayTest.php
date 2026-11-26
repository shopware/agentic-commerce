<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\SetPaymentOrderRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\HandlePaymentMethodRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PaymentFailedException;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderPaymentGateway;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/** @internal */
#[CoversClass(ShopwareOrderPaymentGateway::class)]
final class ShopwareOrderPaymentGatewayTest extends TestCase
{
    private const ORDER_ID = '00000000000000000000000000000001';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000002';
    private const RETURN_URL = 'https://shop.example/account/order/deep-link-code';
    private const REDIRECT_URL = 'https://psp.example/pay/1';

    private AbstractHandlePaymentMethodRoute&MockObject $handlePaymentRoute;

    private AbstractSetPaymentOrderRoute&MockObject $setPaymentOrderRoute;

    private ShopwareOrderPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->handlePaymentRoute = $this->createMock(AbstractHandlePaymentMethodRoute::class);
        $this->setPaymentOrderRoute = $this->createMock(AbstractSetPaymentOrderRoute::class);
        $this->gateway = new ShopwareOrderPaymentGateway($this->handlePaymentRoute, $this->setPaymentOrderRoute);
    }

    #[Test]
    public function testThePaymentRequestCarriesTheOrderTheReturnUrlsAndTheInstrument(): void
    {
        $context = $this->salesChannelContext();
        $request = null;
        $this->handlePaymentRoute->expects(static::once())
            ->method('load')
            ->with(static::isInstanceOf(Request::class), $context)
            ->willReturnCallback(static function (Request $handledRequest) use (&$request): HandlePaymentMethodRouteResponse {
                $request = $handledRequest;

                return new HandlePaymentMethodRouteResponse(null);
            });

        $this->gateway->pay(
            self::ORDER_ID,
            $context,
            new PaymentInstrument('card', 'com.example.psp', ['token' => 'tok_1'], ['postal_code' => '12345']),
            self::RETURN_URL,
        );

        self::assertInstanceOf(Request::class, $request);
        self::assertSame([
            'orderId' => self::ORDER_ID,
            'finishUrl' => self::RETURN_URL,
            'errorUrl' => self::RETURN_URL,
            'ucpPaymentInstrument' => [
                'type' => 'card',
                'handler_id' => 'com.example.psp',
                'credential' => ['token' => 'tok_1'],
                'billing_address' => ['postal_code' => '12345'],
            ],
        ], $request->request->all());
    }

    #[Test]
    public function testAPaymentWithoutRedirectAnswersNull(): void
    {
        $this->handlePaymentRoute->method('load')->willReturn(new HandlePaymentMethodRouteResponse(null));

        self::assertNull($this->gateway->pay(self::ORDER_ID, $this->salesChannelContext(), null, self::RETURN_URL));
    }

    #[Test]
    public function testAPaymentWithRedirectAnswersTheRedirectUrl(): void
    {
        $this->handlePaymentRoute->method('load')->willReturn(new HandlePaymentMethodRouteResponse(new RedirectResponse(self::REDIRECT_URL)));

        self::assertSame(self::REDIRECT_URL, $this->gateway->pay(self::ORDER_ID, $this->salesChannelContext(), null, self::RETURN_URL));
    }

    #[Test]
    public function testARedirectToTheReturnUrlWithAnErrorCodeIsAFailedPayment(): void
    {
        $errorUrl = self::RETURN_URL.'?error-code=CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED';
        $this->handlePaymentRoute->method('load')->willReturn(new HandlePaymentMethodRouteResponse(new RedirectResponse($errorUrl)));

        $this->expectExceptionObject(new PaymentFailedException($errorUrl));

        $this->gateway->pay(self::ORDER_ID, $this->salesChannelContext(), null, self::RETURN_URL);
    }

    #[Test]
    public function testChangingThePaymentMethodSendsTheOrderAndThePaymentMethodOfTheContext(): void
    {
        $context = $this->salesChannelContext();
        $request = null;
        $this->setPaymentOrderRoute->expects(static::once())
            ->method('setPayment')
            ->with(static::isInstanceOf(Request::class), $context)
            ->willReturnCallback(static function (Request $handledRequest) use (&$request): SetPaymentOrderRouteResponse {
                $request = $handledRequest;

                return new SetPaymentOrderRouteResponse();
            });

        $this->gateway->changePaymentMethod(self::ORDER_ID, $context);

        self::assertInstanceOf(Request::class, $request);
        self::assertSame([
            'orderId' => self::ORDER_ID,
            'paymentMethodId' => self::PAYMENT_METHOD_ID,
        ], $request->request->all());
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(self::PAYMENT_METHOD_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        return $context;
    }
}
