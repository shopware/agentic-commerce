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
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerPaymentMethodResolver;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(RegisteredHandlerPaymentMethodResolver::class)]
final class RegisteredHandlerPaymentMethodResolverTest extends TestCase
{
    private const HANDLER_ID = 'com.shopware.invoice';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000001';

    private PaymentHandlerRegistryInterface&MockObject $handlerRegistry;

    private PaymentHandlerInterface&MockObject $paymentHandler;

    private AbstractPaymentMethodRoute&MockObject $paymentMethodRoute;

    private RegisteredHandlerPaymentMethodResolver $resolver;

    protected function setUp(): void
    {
        $this->handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $this->paymentHandler = $this->createMock(PaymentHandlerInterface::class);
        $this->paymentMethodRoute = $this->createMock(AbstractPaymentMethodRoute::class);
        $this->resolver = new RegisteredHandlerPaymentMethodResolver(
            $this->handlerRegistry,
            new SalesChannelPaymentMethods($this->createMock(EntityRepository::class), $this->paymentMethodRoute),
        );
    }

    #[Test]
    public function testThePaymentMethodOfTheHandlerIsReturnedWhenTheBuyerMayUseIt(): void
    {
        $instrument = new PaymentInstrument('invoice', self::HANDLER_ID);
        $requestContext = new RequestContext('shop.example');
        $this->handlerRegistry->method('find')->with(self::HANDLER_ID)->willReturn($this->paymentHandler);
        $this->paymentHandler->expects(static::once())->method('prepareInstrument')->with($instrument, $requestContext)
            ->willReturn(['paymentMethodId' => self::PAYMENT_METHOD_ID, 'token' => '']);
        $this->paymentMethodAvailable(true);

        self::assertSame(self::PAYMENT_METHOD_ID, $this->resolver->resolve($instrument, $this->createMock(SalesChannelContext::class), $requestContext));
    }

    #[Test]
    public function testAHandlerThatIsNotRegisteredIsRefused(): void
    {
        $this->handlerRegistry->method('find')->with('com.example.unknown')->willReturn(null);

        $this->expectExceptionObject(CompletionPaymentException::handlerNotRegistered('com.example.unknown'));

        $this->resolver->resolve(new PaymentInstrument('invoice', 'com.example.unknown'), $this->createMock(SalesChannelContext::class), new RequestContext('shop.example'));
    }

    #[Test]
    public function testAnEntryPointWithoutInstrumentIsRefusedInsteadOfKeepingTheDefaultPaymentMethod(): void
    {
        $this->expectExceptionObject(CompletionPaymentException::handlerNotRegistered(null));

        $this->resolver->resolve(null, $this->createMock(SalesChannelContext::class), new RequestContext('shop.example'));
    }

    #[Test]
    public function testAHandlerThatResolvesToNoPaymentMethodIsRefusedInsteadOfKeepingTheDefaultPaymentMethod(): void
    {
        $this->handlerRegistry->method('find')->willReturn($this->paymentHandler);
        $this->paymentHandler->method('prepareInstrument')->willReturn(['paymentMethodId' => '', 'token' => '']);
        $this->paymentMethodRoute->expects(static::never())->method('load');

        $this->expectExceptionObject(CompletionPaymentException::paymentMethodUnavailable(self::HANDLER_ID));

        $this->resolver->resolve(new PaymentInstrument('invoice', self::HANDLER_ID), $this->createMock(SalesChannelContext::class), new RequestContext('shop.example'));
    }

    #[Test]
    public function testAPaymentMethodThatIsNotAvailableToTheBuyerIsRefused(): void
    {
        $this->handlerRegistry->method('find')->willReturn($this->paymentHandler);
        $this->paymentHandler->method('prepareInstrument')->willReturn(['paymentMethodId' => self::PAYMENT_METHOD_ID, 'token' => '']);
        $this->paymentMethodAvailable(false);

        $this->expectExceptionObject(CompletionPaymentException::paymentMethodUnavailable(self::HANDLER_ID));

        $this->resolver->resolve(new PaymentInstrument('invoice', self::HANDLER_ID), $this->createMock(SalesChannelContext::class), new RequestContext('shop.example'));
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
}
