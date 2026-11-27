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
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerCompletionPayment;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerPaymentMethodResolver;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(RegisteredHandlerCompletionPayment::class)]
final class RegisteredHandlerCompletionPaymentTest extends TestCase
{
    private const HANDLER_ID = 'com.shopware.invoice';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000001';
    private const SALES_CHANNEL_ID = '00000000000000000000000000000002';
    private const LANGUAGE_ID = '00000000000000000000000000000003';
    private const CURRENCY_ID = '00000000000000000000000000000004';
    private const DOMAIN_ID = '00000000000000000000000000000005';
    private const CUSTOMER_ID = '00000000000000000000000000000006';
    private const CONTEXT_TOKEN = 'context-token';

    private PaymentHandlerRegistryInterface&MockObject $handlerRegistry;

    private PaymentHandlerInterface&MockObject $handler;

    private AbstractPaymentMethodRoute&MockObject $paymentMethodRoute;

    private AbstractContextSwitchRoute&MockObject $contextSwitchRoute;

    private SalesChannelContextServiceInterface&MockObject $contextService;

    private RegisteredHandlerCompletionPayment $applier;

    protected function setUp(): void
    {
        $this->handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $this->handler = $this->createMock(PaymentHandlerInterface::class);
        $this->paymentMethodRoute = $this->createMock(AbstractPaymentMethodRoute::class);
        $this->contextSwitchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $this->contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $this->applier = new RegisteredHandlerCompletionPayment(
            new RegisteredHandlerPaymentMethodResolver(
                $this->handlerRegistry,
                new SalesChannelPaymentMethods($this->createMock(EntityRepository::class), $this->paymentMethodRoute),
            ),
            $this->contextSwitchRoute,
            $this->contextService,
        );
    }

    #[Test]
    public function testThePaymentMethodOfTheHandlerIsSwitchedToAndTheSwitchedContextReturned(): void
    {
        $instrument = new PaymentInstrument('invoice', self::HANDLER_ID);
        $context = $this->salesChannelContext();
        $requestContext = new RequestContext('shop.example');
        $switchedContext = $this->createMock(SalesChannelContext::class);
        $this->handlerRegistry->method('find')->with(self::HANDLER_ID)->willReturn($this->handler);
        $this->handler->expects(static::once())->method('prepareInstrument')->with($instrument, $requestContext)
            ->willReturn(['paymentMethodId' => self::PAYMENT_METHOD_ID, 'token' => '']);
        $this->paymentMethodAvailable(true);
        $this->contextSwitchRoute->expects(static::once())
            ->method('switchContext')
            ->willReturnCallback(static function (RequestDataBag $data, SalesChannelContext $switchedFrom) use ($context): ContextTokenResponse {
                self::assertSame(['paymentMethodId' => self::PAYMENT_METHOD_ID], $data->all());
                self::assertSame($context, $switchedFrom);

                return new ContextTokenResponse(self::CONTEXT_TOKEN);
            });
        $this->contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($switchedContext): SalesChannelContext {
                self::assertSame(self::SALES_CHANNEL_ID, $parameters->getSalesChannelId());
                self::assertSame(self::CONTEXT_TOKEN, $parameters->getToken());
                self::assertSame(self::LANGUAGE_ID, $parameters->getLanguageId());
                self::assertSame(self::CURRENCY_ID, $parameters->getCurrencyId());
                self::assertSame(self::DOMAIN_ID, $parameters->getDomainId());
                self::assertSame(self::CUSTOMER_ID, $parameters->getCustomerId());

                return $switchedContext;
            });

        self::assertSame($switchedContext, $this->applier->apply($instrument, $context, $requestContext));
    }

    #[Test]
    public function testAnInstrumentTheResolverRefusesSwitchesNothing(): void
    {
        $this->handlerRegistry->method('find')->with('com.example.unknown')->willReturn(null);
        $this->contextSwitchRoute->expects(static::never())->method('switchContext');

        $this->expectExceptionObject(CompletionPaymentException::handlerNotRegistered('com.example.unknown'));

        $this->applier->apply(new PaymentInstrument('invoice', 'com.example.unknown'), $this->salesChannelContext(), new RequestContext('shop.example'));
    }

    #[Test]
    public function testItIsTheEndOfTheDecorationChain(): void
    {
        $this->expectException(DecorationPatternException::class);

        $this->applier->getDecorated();
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

    private function salesChannelContext(): SalesChannelContext&MockObject
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getToken')->willReturn(self::CONTEXT_TOKEN);
        $context->method('getLanguageId')->willReturn(self::LANGUAGE_ID);
        $context->method('getCurrencyId')->willReturn(self::CURRENCY_ID);
        $context->method('getDomainId')->willReturn(self::DOMAIN_ID);
        $context->method('getCustomerId')->willReturn(self::CUSTOMER_ID);

        return $context;
    }
}
