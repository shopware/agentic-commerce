<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\RegisteredHandlerPaymentMethodResolver;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompletionPaymentCheck;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(UcpCheckoutCompletionPaymentCheck::class)]
final class UcpCheckoutCompletionPaymentCheckTest extends TestCase
{
    private const HANDLER_ID = 'com.shopware.invoice';
    private const CHECKOUT_ID = 'checkout-id';
    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000001';

    private PaymentHandlerRegistryInterface&MockObject $handlerRegistry;

    private PaymentHandlerInterface&MockObject $paymentHandler;

    private AbstractPaymentMethodRoute&MockObject $paymentMethodRoute;

    private SalesChannelContextServiceInterface&MockObject $contextService;

    private SalesChannelContextPersister&MockObject $persister;

    protected function setUp(): void
    {
        $this->handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $this->paymentHandler = $this->createMock(PaymentHandlerInterface::class);
        $this->paymentMethodRoute = $this->createMock(AbstractPaymentMethodRoute::class);
        $this->contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $this->persister = $this->createMock(SalesChannelContextPersister::class);
    }

    #[Test]
    public function testAnAvailablePaymentMethodIsNotRefused(): void
    {
        $this->handlerOfPaymentMethod(self::PAYMENT_METHOD_ID);
        $this->paymentMethodAvailable(true);
        $this->contextService->method('get')->willReturn($this->createMock(SalesChannelContext::class));

        self::assertNull($this->refusal([self::HANDLER_ID], 'ready_for_complete'));
    }

    #[Test]
    public function testAPaymentMethodTheBuyerMayNotUseIsRefusedLikeTheApplierWould(): void
    {
        $this->handlerOfPaymentMethod(self::PAYMENT_METHOD_ID);
        $this->paymentMethodAvailable(false);
        $this->contextService->method('get')->willReturn($this->createMock(SalesChannelContext::class));

        self::assertSame([
            'type' => 'error',
            'content' => 'The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ], $this->refusal([self::HANDLER_ID], 'ready_for_complete')?->toArray());
    }

    #[Test]
    public function testAHandlerWithoutAPaymentMethodInTheSalesChannelIsRefused(): void
    {
        $this->handlerOfPaymentMethod('');
        $this->contextService->method('get')->willReturn($this->createMock(SalesChannelContext::class));

        self::assertSame(
            'The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.',
            $this->refusal([self::HANDLER_ID], 'ready_for_complete')?->content,
        );
    }

    #[Test]
    public function testAHandlerThatIsNotRegisteredIsRefused(): void
    {
        $this->handlerRegistry->method('find')->willReturn(null);
        $this->contextService->method('get')->willReturn($this->createMock(SalesChannelContext::class));

        self::assertSame('No payment handler "com.example.unknown" is registered.', $this->refusal(['com.example.unknown'], 'ready_for_complete')?->content);
    }

    #[Test]
    public function testTheContextOfTheCheckoutIsResolvedFromItsStoredContextToken(): void
    {
        $this->persister->method('load')->willReturn(['swagAgenticCommerce' => ['ucpCheckout' => ['shopwareContextToken' => 'rotated-context-token']]]);
        $this->handlerOfPaymentMethod(self::PAYMENT_METHOD_ID);
        $this->paymentMethodAvailable(true);
        $this->contextService->expects(static::once())->method('get')->willReturnCallback(
            function (SalesChannelContextServiceParameters $parameters): SalesChannelContext {
                self::assertSame('rotated-context-token', $parameters->getToken());
                self::assertSame(self::SALES_CHANNEL_ID, $parameters->getSalesChannelId());

                return $this->createMock(SalesChannelContext::class);
            },
        );

        $this->refusal([self::HANDLER_ID], 'ready_for_complete');
    }

    #[Test]
    public function testAMissingRuntimeConfigurationIsReportedInsteadOfFailingThePreview(): void
    {
        $gate = new CompletionPaymentGate(new AdvertisedPaymentHandlers(static function (): never {
            throw new \LogicException('The profile must not be built without a runtime configuration.');
        }));

        $refusal = $this->refusal([self::HANDLER_ID], 'ready_for_complete', $gate, new RequestContext('shop.example'));

        self::assertSame(UcpConfigException::RUNTIME_CONFIGURATION_MISSING, $refusal?->code);
        self::assertSame('unrecoverable', $refusal->severity);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function wrongInstrumentCountProvider(): iterable
    {
        yield 'no instrument' => [[]];
        yield 'two instruments' => [[self::HANDLER_ID, 'com.shopware.prepayment']];
    }

    /**
     * @param list<string> $handlerIds
     */
    #[DataProvider('wrongInstrumentCountProvider')]
    #[Test]
    public function testAnInstrumentCountOtherThanOneIsRefusedLikeTheSdkWithoutBuildingTheProfile(array $handlerIds): void
    {
        $gate = new CompletionPaymentGate(new AdvertisedPaymentHandlers(static function (): never {
            throw new \LogicException('The profile must not be built for a wrong instrument count.');
        }));
        $this->contextService->expects(static::never())->method('get');

        $refusal = $this->refusal($handlerIds, 'ready_for_complete', $gate);

        self::assertSame([
            'type' => 'error',
            'content' => 'Complete Checkout requires exactly one payment instrument.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testTheGateRefusalOfASingleInstrumentIsPassedThroughAsItIs(): void
    {
        $this->handlerRegistry->expects(static::never())->method('find');
        $this->contextService->expects(static::never())->method('get');

        $refusal = $this->refusal(['com.example.deactivated'], 'ready_for_complete');

        self::assertSame('$.payment.instruments[0].handler_id', $refusal?->path);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function completedStatusProvider(): iterable
    {
        yield 'as a value' => ['completed'];
        yield 'as an enum' => [CheckoutStatus::Completed];
    }

    #[DataProvider('completedStatusProvider')]
    #[Test]
    public function testTheCheckoutIsNotResolvedForACompletedCheckout(mixed $status): void
    {
        $this->handlerRegistry->expects(static::never())->method('find');
        $this->contextService->expects(static::never())->method('get');

        self::assertNull($this->refusal([self::HANDLER_ID], $status));
    }

    /**
     * @param list<string> $handlerIds
     */
    private function refusal(array $handlerIds, mixed $status, ?CompletionPaymentGate $gate = null, ?RequestContext $requestContext = null): ?Message
    {
        $advertisedHandlers = RecordingProfileBuilder::advertising(self::HANDLER_ID, 'com.example.unknown');

        return (new UcpCheckoutCompletionPaymentCheck(
            $gate ?? new CompletionPaymentGate(new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $advertisedHandlers)),
            new RegisteredHandlerPaymentMethodResolver(
                $this->handlerRegistry,
                new SalesChannelPaymentMethods($this->createMock(EntityRepository::class), $this->paymentMethodRoute),
            ),
            new SalesChannelContextResolver($this->domainResolver(), $this->contextService, $this->persister),
            new CheckoutSessionStore($this->persister),
        ))->refusal(
            $handlerIds,
            $status,
            self::CHECKOUT_ID,
            $requestContext ?? new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example')),
        );
    }

    private function handlerOfPaymentMethod(string $paymentMethodId): void
    {
        $this->paymentHandler->method('prepareInstrument')->willReturn(['paymentMethodId' => $paymentMethodId, 'token' => '']);
        $this->handlerRegistry->method('find')->willReturn($this->paymentHandler);
    }

    private function paymentMethodAvailable(bool $isAvailable): void
    {
        $this->paymentMethodRoute->method('load')->willReturnCallback(
            static function (mixed $request, SalesChannelContext $context, Criteria $criteria) use ($isAvailable): PaymentMethodRouteResponse {
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

    private function domainResolver(): SalesChannelDomainResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('11111111111111111111111111111111');
        $domain->setSalesChannelId(self::SALES_CHANNEL_ID);
        $domain->setLanguageId('33333333333333333333333333333333');
        $domain->setCurrencyId('44444444444444444444444444444444');
        $domain->setUrl('https://shop.example');
        $domains = new SalesChannelDomainCollection([$domain]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                $domains->count(),
                $domains,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelDomainResolver($repository);
    }
}
