<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartDeleteRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemAddRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemRemoveRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemUpdateRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartLoadRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareCheckoutAdapter;
use Swag\AgenticCommerce\Ucp\Cart\CartSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutLegalLinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CheckoutPaymentSelector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentProcessor;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PlacedOrderPaymentMethodGate;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderPaymentGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/** @internal */
#[CoversClass(ShopwareCheckoutAdapter::class)]
final class ShopwareCheckoutAdapterTest extends TestCase
{
    use BuildsPlacedOrderPaymentMethodGate;

    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const OPEN_CHECKOUT_ID = 'open-checkout-token';
    private const CONTINUE_URL_TEMPLATE = 'https://shop.example/checkout/{checkoutId}';
    private const LEGAL_LINK_DOMAIN_ID = '33333333333333333333333333333333';
    private const TOS_PAGE_ID = '44444444444444444444444444444444';
    private const PRIVACY_PAGE_ID = '55555555555555555555555555555555';
    private const ADVERTISED_HANDLER_ID = 'com.shopware.invoice';
    private const PLACED_CHECKOUT_ID = 'checkout-context-token';
    private const PLACED_ORDER_ID = '99999999999999999999999999999999';
    private const PAYMENT_REDIRECT_URL = 'https://psp.example/pay/1';

    private bool $isPaymentMethodAvailable = true;

    #[Test]
    public function testCompletedCheckoutReadsOrderWithCheckoutSalesChannelContext(): void
    {
        $checkoutId = 'checkout-context-token';
        $shopwareContextToken = 'rotated-shopware-context-token';
        $salesChannelId = '22222222222222222222222222222222';
        $orderId = '99999999999999999999999999999999';

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn($salesChannelId);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());
        $this->withLegalLinkDomain($salesChannelContext);

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($shopwareContextToken, $salesChannelId, $salesChannelContext): SalesChannelContext {
                self::assertSame($salesChannelId, $parameters->getSalesChannelId());
                self::assertSame($shopwareContextToken, $parameters->getToken());
                self::assertSame('customer-id', $parameters->getCustomerId());

                return $salesChannelContext;
            });

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::exactly(2))
            ->method('load')
            ->willReturnCallback(static function (string $token, string $requestedSalesChannelId, ?string $customerId = null) use ($checkoutId, $shopwareContextToken, $salesChannelId, $orderId): array {
                self::assertSame($salesChannelId, $requestedSalesChannelId);
                self::assertNull($customerId);

                if ($token === $checkoutId) {
                    return [
                        'swagAgenticCommerce' => [
                            'ucpCheckout' => [
                                'status' => CheckoutStatus::Completed->value,
                                'orderId' => $orderId,
                                'shopwareContextToken' => $shopwareContextToken,
                            ],
                        ],
                    ];
                }

                self::assertSame($shopwareContextToken, $token);

                return ['customerId' => 'customer-id'];
            });

        $order = $this->order($orderId);
        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(static function (
                Request $_request,
                SalesChannelContext $context,
                Criteria $criteria,
            ) use ($salesChannelContext, $orderId, $order): OrderRouteResponse {
                self::assertSame($salesChannelContext, $context);
                self::assertSame([$orderId], $criteria->getIds());

                $orders = new OrderCollection([$order]);

                return new OrderRouteResponse(new EntitySearchResult(
                    'order',
                    $orders->count(),
                    $orders,
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                ));
            });

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);
        $mapper = new ShopwareDataMapper();

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $checkoutSessionStore, new RequestStack()),
            $mapper,
            $checkoutSessionStore,
            new CheckoutSessionManager($checkoutSessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelector(),
        );

        $checkout = $adapter->getCheckout($checkoutId, new RequestContext('shop.example'));

        self::assertSame($checkoutId, $checkout->id);
        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
        self::assertSame($this->legalLinkArrays(), $checkout->toArray()['links']);
    }

    /**
     * @return iterable<string, array{callable(ShopwareCheckoutAdapter, string): Checkout}>
     */
    public static function completionEntryPointProvider(): iterable
    {
        yield 'completeCheckout' => [
            static fn (ShopwareCheckoutAdapter $adapter, string $checkoutId): Checkout => $adapter->completeCheckout($checkoutId, new RequestContext('shop.example')),
        ];
        yield 'completeCheckoutFromRequest' => [
            static fn (ShopwareCheckoutAdapter $adapter, string $checkoutId): Checkout => $adapter->completeCheckoutFromRequest(new CheckoutCompleteRequest($checkoutId), new RequestContext('shop.example')),
        ];
    }

    /**
     * @param callable(ShopwareCheckoutAdapter, string): Checkout $complete
     */
    #[DataProvider('completionEntryPointProvider')]
    #[Test]
    public function testCompletingAnAlreadyCompletedCheckoutReplaysTheOrderWithLegalLinks(callable $complete): void
    {
        $checkoutId = 'checkout-context-token';
        $shopwareContextToken = 'rotated-shopware-context-token';
        $orderId = '99999999999999999999999999999999';

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());
        $this->withLegalLinkDomain($salesChannelContext);

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn([
            'swagAgenticCommerce' => [
                'ucpCheckout' => [
                    'status' => CheckoutStatus::Completed->value,
                    'shopwareContextToken' => $shopwareContextToken,
                ],
            ],
        ]);

        $order = $this->order($orderId);
        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->method('load')->willReturnCallback(static function (
            Request $_request,
            SalesChannelContext $_context,
            Criteria $criteria,
        ) use ($order): OrderRouteResponse {
            $orders = new OrderCollection([$order]);

            return new OrderRouteResponse(new EntitySearchResult('order', 1, $orders, null, $criteria, Context::createDefaultContext()));
        });

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with($checkoutId)->willReturn($orderId);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $checkoutSessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            new CheckoutSessionManager($checkoutSessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelector(),
        );

        $checkout = $complete($adapter, $checkoutId)->toArray();

        self::assertSame(CheckoutStatus::Completed->value, $checkout['status']);
        self::assertSame($orderId, $checkout['order']['id']);
        self::assertSame('https://shop.example/checkout/'.$checkoutId, $checkout['continue_url']);
        self::assertSame([
            ['type' => 'order', 'url' => 'https://shop.example/checkout/'.$checkoutId, 'title' => 'Order details'],
            ...$this->legalLinkArrays(),
        ], $checkout['links']);
    }

    #[Test]
    public function testCompletingDuringAConcurrentCompletionReplaysTheOrder(): void
    {
        $checkoutId = 'checkout-context-token';
        $orderId = '99999999999999999999999999999999';

        $checkout = $this->adapterRacingACompletion($checkoutId, $orderId)
            ->completeCheckout($checkoutId, new RequestContext('shop.example'));

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
    }

    #[Test]
    public function testReadingDuringAConcurrentCompletionReturnsTheOrder(): void
    {
        $checkoutId = 'checkout-context-token';
        $orderId = '99999999999999999999999999999999';

        $checkout = $this->adapterRacingACompletion($checkoutId, $orderId)
            ->getCheckout($checkoutId, new RequestContext('shop.example'));

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
    }

    #[Test]
    public function testCompletingWhileAConcurrentCompletionPlacesTheOrderReplaysItWithoutAContextTokenHeader(): void
    {
        $checkoutId = 'checkout-context-token';
        $rotatedContextToken = 'rotated-shopware-context-token';
        $orderId = '99999999999999999999999999999999';

        $cartLoadRoute = new class($this->cart()) extends AbstractCartLoadRoute {
            public bool $cartLoaded = false;

            public function __construct(private readonly Cart $cart)
            {
            }

            public function getDecorated(): AbstractCartLoadRoute
            {
                throw new \BadMethodCallException('Decoration is not supported in tests.');
            }

            public function load(Request $request, SalesChannelContext $context, ?Cart $cart = null): CartResponse
            {
                $this->cartLoaded = true;

                return new CartResponse($this->cart);
            }
        };

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturnCallback(static function (string $token) use ($cartLoadRoute, $checkoutId, $rotatedContextToken, $orderId): array {
            if ($token !== $checkoutId || !$cartLoadRoute->cartLoaded) {
                return [];
            }

            return ['swagAgenticCommerce' => ['ucpCheckout' => [
                'status' => CheckoutStatus::Completed->value,
                'orderId' => $orderId,
                'shopwareContextToken' => $rotatedContextToken,
            ]]];
        });

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with($checkoutId)->willReturnCallback(static fn (): ?string => $cartLoadRoute->cartLoaded ? $orderId : null);
        $completionStore->expects(static::never())->method('complete');

        $order = $this->order($orderId);
        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->method('load')->willReturnCallback(static fn (
            Request $_request,
            SalesChannelContext $_context,
            Criteria $criteria,
        ): OrderRouteResponse => new OrderRouteResponse(new EntitySearchResult('order', 1, new OrderCollection([$order]), null, $criteria, Context::createDefaultContext())));

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );
        $orderGateway = new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $checkoutSessionStore, new RequestStack());
        $checkoutSessionManager = new CheckoutSessionManager($checkoutSessionStore);
        $continueUrlBuilder = $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE);

        $adapter = new ShopwareCheckoutAdapter(
            new ShopwareCartGateway(
                $contextResolver,
                $cartLoadRoute,
                new RecordingCartItemAddRoute(),
                new RecordingCartItemUpdateRoute(),
                new RecordingCartItemRemoveRoute(),
                new RecordingCartDeleteRoute(),
                new ShopwareDataMapper(),
                new ShopwareVersionDetector(versionOverride: '6.6.0.0'),
                new CartSessionStore($persister),
                $this->createMock(AbstractProductListRoute::class),
            ),
            $orderGateway,
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            $checkoutSessionManager,
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $continueUrlBuilder,
            new CheckoutCompleter(
                $orderGateway,
                new ShopwareDataMapper(),
                $this->createMock(GuestCustomerContextProvisionerInterface::class),
                $this->uninitialized(UcpConfigService::class),
                $checkoutSessionManager,
                $completionStore,
                new LockFactory(new InMemoryStore()),
                $continueUrlBuilder,
                $this->uninitialized(CheckoutWebhookUrlGuard::class),
                $this->createMock(OrderWebhookPublisherInterface::class),
                new OrderPermalinkBuilder(),
                new CompletionPaymentProcessor($this->passThroughApplier(), $this->createMock(OrderPaymentGatewayInterface::class), new RecordingCartLoadRoute($this->cart()), $this->createMock(AbstractContextSwitchRoute::class), $this->placedOrderGate()),
                $this->legalLinkBuilder(),
            ),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelector(self::ADVERTISED_HANDLER_ID),
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest($checkoutId, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
    }

    private function adapterRacingACompletion(string $checkoutId, string $orderId): ShopwareCheckoutAdapter
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());

        $storageReads = 0;
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturnCallback(static function () use (&$storageReads, $orderId): array {
            $sessionStateDuringGuestRegistration = [];
            $sessionStateAfterCompletion = [
                'status' => CheckoutStatus::Completed->value,
                'orderId' => $orderId,
                'shopwareContextToken' => 'rotated-shopware-context-token',
            ];

            return ['swagAgenticCommerce' => ['ucpCheckout' => 1 === ++$storageReads ? $sessionStateDuringGuestRegistration : $sessionStateAfterCompletion]];
        });

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with($checkoutId)->willReturnCallback(static function () use (&$storageReads, $orderId): string {
            ++$storageReads;

            return $orderId;
        });

        $order = $this->order($orderId);
        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->method('load')->willReturnCallback(static fn (
            Request $_request,
            SalesChannelContext $_context,
            Criteria $criteria,
        ): OrderRouteResponse => new OrderRouteResponse(new EntitySearchResult('order', 1, new OrderCollection([$order]), null, $criteria, Context::createDefaultContext())));

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );

        return new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $checkoutSessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            new CheckoutSessionManager($checkoutSessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelector(),
        );
    }

    #[Test]
    public function testCreateCheckoutAppendsLegalLinksAfterTheContinueLink(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext());

        $checkout = $adapter->createCheckout(
            new CheckoutCreateRequest([new UcpLineItem('product-a', 'product-a', 0.0, 1)]),
            new RequestContext('shop.example'),
        )->toArray();

        $this->assertOpenCheckoutWithLegalLinks($checkout, CheckoutStatus::Incomplete);
    }

    #[Test]
    public function testGetCheckoutAppendsLegalLinksAfterTheContinueLink(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext());

        $checkout = $adapter->getCheckout(self::OPEN_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        $this->assertOpenCheckoutWithLegalLinks($checkout, CheckoutStatus::Incomplete);
    }

    #[Test]
    public function testUpdateCheckoutAppendsLegalLinksAfterTheContinueLink(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext());

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(
                self::OPEN_CHECKOUT_ID,
                [new UcpLineItem('product-a', 'product-a', 0.0, 1)],
                new Buyer(email: 'buyer@example.com'),
            ),
            new RequestContext('shop.example'),
        )->toArray();

        $this->assertOpenCheckoutWithLegalLinks($checkout, CheckoutStatus::ReadyForComplete);
        self::assertSame('buyer@example.com', $checkout['buyer']['email']);
    }

    #[Test]
    public function testCancelCheckoutAppendsLegalLinksAfterTheContinueLink(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext());

        $checkout = $adapter->cancelCheckout(self::OPEN_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        self::assertSame(CheckoutStatus::Canceled->value, $checkout['status']);
        self::assertSame([], $checkout['line_items']);
        self::assertSame([
            ['type' => 'continue', 'url' => 'https://shop.example/checkout/'.self::OPEN_CHECKOUT_ID, 'title' => 'Continue checkout'],
            ...$this->legalLinkArrays(),
        ], $checkout['links']);
    }

    #[Test]
    public function testOpenCheckoutWithoutLegalLinksKeepsOnlyTheContinueLink(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(withLegalLinkDomain: false));

        $checkout = $adapter->getCheckout(self::OPEN_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        self::assertSame([
            ['type' => 'continue', 'url' => 'https://shop.example/checkout/'.self::OPEN_CHECKOUT_ID, 'title' => 'Continue checkout'],
        ], $checkout['links']);
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function assertOpenCheckoutWithLegalLinks(array $checkout, CheckoutStatus $status): void
    {
        $continueUrl = 'https://shop.example/checkout/'.self::OPEN_CHECKOUT_ID;

        self::assertSame(self::OPEN_CHECKOUT_ID, $checkout['id']);
        self::assertSame($status->value, $checkout['status']);
        self::assertCount(1, $checkout['line_items']);
        self::assertSame('product-a', $checkout['line_items'][0]['item']['id']);
        self::assertContains('total', array_column($checkout['totals'], 'type'));
        self::assertSame($continueUrl, $checkout['continue_url']);
        self::assertSame([
            ['type' => 'continue', 'url' => $continueUrl, 'title' => 'Continue checkout'],
            ...$this->legalLinkArrays(),
        ], $checkout['links']);
    }

    /**
     * @return iterable<string, array{string|null, string, list<string>}>
     */
    public static function storedContextTokenProvider(): iterable
    {
        yield 'a rotated context token' => ['rotated-shopware-context-token', 'rotated-shopware-context-token', ['rotated-shopware-context-token', self::OPEN_CHECKOUT_ID]];
        yield 'no stored context token' => [null, self::OPEN_CHECKOUT_ID, [self::OPEN_CHECKOUT_ID]];
    }

    /**
     * @param list<string> $expectedSavedTokens
     */
    #[Test]
    #[DataProvider('storedContextTokenProvider')]
    public function testUpdateCheckoutWorksOnTheCartOfTheStoredContextToken(?string $storedContextToken, string $expectedCartToken, array $expectedSavedTokens): void
    {
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $savedTokens = [];
        $adapter = $this->openCheckoutAdapterWithStoredContextToken($storedContextToken, $cartLoadRoute, $savedTokens);

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(
                self::OPEN_CHECKOUT_ID,
                [new UcpLineItem('product-a', 'product-a', 0.0, 1)],
                new Buyer(email: 'buyer@example.com'),
            ),
            new RequestContext('shop.example'),
        )->toArray();

        self::assertSame([$expectedCartToken], array_values(array_unique($cartLoadRoute->loadedTokens)));
        self::assertSame($expectedSavedTokens, $savedTokens);
        self::assertSame(self::OPEN_CHECKOUT_ID, $checkout['id']);
    }

    /**
     * @param list<string> $expectedSavedTokens
     */
    #[Test]
    #[DataProvider('storedContextTokenProvider')]
    public function testCancelCheckoutWorksOnTheCartOfTheStoredContextToken(?string $storedContextToken, string $expectedCartToken, array $expectedSavedTokens): void
    {
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $savedTokens = [];
        $adapter = $this->openCheckoutAdapterWithStoredContextToken($storedContextToken, $cartLoadRoute, $savedTokens);

        $checkout = $adapter->cancelCheckout(self::OPEN_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        self::assertSame([$expectedCartToken], array_values(array_unique($cartLoadRoute->loadedTokens)));
        self::assertSame($expectedSavedTokens, $savedTokens);
        self::assertSame(self::OPEN_CHECKOUT_ID, $checkout['id']);
        self::assertSame(CheckoutStatus::Canceled->value, $checkout['status']);
    }

    /**
     * @param list<string> $savedTokens receives the token of every session save
     */
    private function openCheckoutAdapterWithStoredContextToken(?string $storedContextToken, RecordingCartLoadRoute $cartLoadRoute, array &$savedTokens): ShopwareCheckoutAdapter
    {
        $persister = $this->openCheckoutPersister(storedContextToken: $storedContextToken);
        $persister->method('save')->willReturnCallback(static function (string $token) use (&$savedTokens): void {
            $savedTokens[] = $token;
        });

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->method('get')->willReturnCallback(
            fn (SalesChannelContextServiceParameters $parameters): SalesChannelContext => $this->openCheckoutContext(token: $parameters->getToken()),
        );

        return $this->openCheckoutAdapter(
            $this->openCheckoutContext(),
            cartLoadRoute: $cartLoadRoute,
            persister: $persister,
            contextService: $contextService,
        );
    }

    private function openCheckoutPersister(?string $buyerEmail = null, ?string $storedContextToken = null): SalesChannelContextPersister&MockObject
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn([
            'swagAgenticCommerce' => ['ucpCheckout' => [
                'status' => CheckoutStatus::Incomplete->value,
                ...(null === $buyerEmail ? [] : ['buyer' => ['email' => $buyerEmail]]),
                ...(null === $storedContextToken ? [] : ['shopwareContextToken' => $storedContextToken]),
            ]],
        ]);

        return $persister;
    }

    private function openCheckoutAdapter(
        SalesChannelContext $salesChannelContext,
        ?CheckoutPaymentSelector $paymentSelector = null,
        ?CheckoutCompleter $checkoutCompleter = null,
        ?RecordingCartLoadRoute $cartLoadRoute = null,
        ?string $buyerEmail = null,
        ?SalesChannelContextPersister $persister = null,
        ?SalesChannelContextServiceInterface $contextService = null,
    ): ShopwareCheckoutAdapter {
        $persister ??= $this->openCheckoutPersister($buyerEmail);

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            $contextService ?? new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $cartGateway = new ShopwareCartGateway(
            $contextResolver,
            $cartLoadRoute ?? new RecordingCartLoadRoute($this->cart()),
            new RecordingCartItemAddRoute(),
            new RecordingCartItemUpdateRoute(),
            new RecordingCartItemRemoveRoute(),
            new RecordingCartDeleteRoute(),
            new ShopwareDataMapper(),
            new ShopwareVersionDetector(versionOverride: '6.6.0.0'),
            new CartSessionStore($persister),
            $this->createMock(AbstractProductListRoute::class),
        );

        return new ShopwareCheckoutAdapter(
            $cartGateway,
            $this->createMock(OrderGatewayInterface::class),
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            new CheckoutSessionManager($checkoutSessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $checkoutCompleter ?? $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $paymentSelector ?? $this->paymentSelector(),
        );
    }

    /**
     * @return iterable<string, array{list<PaymentInstrument>, string, string}>
     */
    public static function refusedCompletionProvider(): iterable
    {
        yield 'a handler the profile does not advertise' => [
            [new PaymentInstrument('invoice', 'com.example.deactivated')],
            'The payment handler "com.example.deactivated" is not one this business advertises.',
            '$.payment.instruments[0].handler_id',
        ];
        yield 'no instrument' => [
            [],
            'Completing a checkout needs a payment instrument, and this entry point carries none.',
            '$.payment.instruments',
        ];
    }

    /**
     * @param list<PaymentInstrument> $instruments
     */
    #[DataProvider('refusedCompletionProvider')]
    #[Test]
    public function testRefusedCompletionOfACheckoutWithoutBuyerKeepsItIncompleteWithAPaymentFailedMessage(array $instruments, string $expectedContent, string $expectedPath): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelector(self::ADVERTISED_HANDLER_ID));

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::OPEN_CHECKOUT_ID, $instruments),
            $this->completionRequestContext(),
        )->toArray();

        $this->assertRefusedCheckout($checkout, $expectedContent, $expectedPath);
    }

    #[Test]
    public function testRefusedCompletionOfAReadyCheckoutKeepsItReadyForComplete(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelector(self::ADVERTISED_HANDLER_ID), buyerEmail: 'buyer@example.com');

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::OPEN_CHECKOUT_ID, [new PaymentInstrument('invoice', 'com.example.deactivated')]),
            $this->completionRequestContext(),
        )->toArray();

        $this->assertRefusedCheckout(
            $checkout,
            'The payment handler "com.example.deactivated" is not one this business advertises.',
            '$.payment.instruments[0].handler_id',
            CheckoutStatus::ReadyForComplete,
        );
    }

    #[Test]
    public function testCompletingThroughTheLegacyEntryPointCountsAsNoInstrument(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelector(self::ADVERTISED_HANDLER_ID));

        $checkout = $adapter->completeCheckout(self::OPEN_CHECKOUT_ID, $this->completionRequestContext())->toArray();

        $this->assertRefusedCheckout($checkout, 'Completing a checkout needs a payment instrument, and this entry point carries none.', '$.payment.instruments');
    }

    #[Test]
    public function testCompletingWithoutRuntimeConfigurationFailsInsteadOfAnsweringWithARecoverableRefusal(): void
    {
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelector(self::ADVERTISED_HANDLER_ID));

        $this->expectException(UcpConfigException::class);

        $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::OPEN_CHECKOUT_ID, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testOneInstrumentNamingAnAdvertisedHandlerReachesTheCompleter(): void
    {
        $presentedInstrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $applier = new class extends AbstractCompletionPaymentApplier {
            public ?PaymentInstrument $appliedInstrument = null;

            public function getDecorated(): AbstractCompletionPaymentApplier
            {
                throw new \BadMethodCallException('Decoration is not supported in tests.');
            }

            public function apply(?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): SalesChannelContext
            {
                $this->appliedInstrument = $instrument;

                throw new \RuntimeException('Stopped before the order is placed.');
            }
        };
        $adapter = $this->openCheckoutAdapter(
            $this->openCheckoutContext(),
            $this->paymentSelector(self::ADVERTISED_HANDLER_ID),
            $this->checkoutCompleter($applier),
        );

        try {
            $adapter->completeCheckoutFromRequest(
                new CheckoutCompleteRequest(self::OPEN_CHECKOUT_ID, [$presentedInstrument]),
                $this->completionRequestContext(),
            );
            self::fail('The applier stops the completion, so it must not return a checkout.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Stopped before the order is placed.', $exception->getMessage());
        }

        self::assertSame($presentedInstrument, $applier->appliedInstrument);
    }

    #[Test]
    public function testTheSelectedInstrumentOfACompletionReachesTheCompleter(): void
    {
        $instrumentOfOtherHandler = new PaymentInstrument('invoice', 'com.shopware.prepayment');
        $selectedInstrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID, selected: true);
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->expects(static::once())->method('apply')->with($selectedInstrument)->willThrowException(new \RuntimeException('Stopped before the order is placed.'));
        $adapter = $this->openCheckoutAdapter(
            $this->openCheckoutContext(),
            $this->paymentSelector(self::ADVERTISED_HANDLER_ID),
            $this->checkoutCompleter($applier),
        );

        $this->expectException(\RuntimeException::class);

        $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::OPEN_CHECKOUT_ID, [$instrumentOfOtherHandler, $selectedInstrument]),
            $this->completionRequestContext(),
        );
    }

    #[Test]
    public function testCreatingACheckoutWithAnInstrumentSwitchesThePaymentMethodAndReloadsTheCart(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $context = $this->openCheckoutContext();
        $requestContext = $this->completionRequestContext();
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->expects(static::once())->method('apply')->with($instrument, $context, $requestContext)->willReturnArgument(1);
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $adapter = $this->openCheckoutAdapter($context, $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID), null, $cartLoadRoute);

        $checkout = $adapter->createCheckout(
            new CheckoutCreateRequest([new UcpLineItem('product-a', 'product-a', 0.0, 1)], payment: $instrument),
            $requestContext,
        )->toArray();

        self::assertCount(2, $cartLoadRoute->loadedTokens, 'The cart is loaded again with the switched payment method');
        self::assertSame([], $checkout['messages']);
    }

    #[Test]
    public function testUpdatingACheckoutWithAnInstrumentSwitchesThePaymentMethodAndReloadsTheCart(): void
    {
        $instrument = new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID);
        $context = $this->openCheckoutContext();
        $requestContext = $this->completionRequestContext();
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->expects(static::once())->method('apply')->with($instrument, $context, $requestContext)->willReturnArgument(1);
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $adapter = $this->openCheckoutAdapter($context, $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID), null, $cartLoadRoute);

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::OPEN_CHECKOUT_ID, [new UcpLineItem('product-a', 'product-a', 0.0, 1)], new Buyer(email: 'buyer@example.com'), payment: $instrument),
            $requestContext,
        )->toArray();

        self::assertCount(2, $cartLoadRoute->loadedTokens);
        self::assertSame([], $checkout['messages']);
        self::assertSame(CheckoutStatus::ReadyForComplete->value, $checkout['status']);
    }

    #[Test]
    public function testAnInstrumentNamingAHandlerTheProfileDoesNotAdvertiseKeepsTheCheckoutOpenWithTheRefusal(): void
    {
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->expects(static::never())->method('apply');
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID), null, $cartLoadRoute);

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::OPEN_CHECKOUT_ID, [new UcpLineItem('product-a', 'product-a', 0.0, 1)], new Buyer(email: 'buyer@example.com'), payment: new PaymentInstrument('invoice', 'com.example.deactivated')),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::ReadyForComplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The payment handler "com.example.deactivated" is not one this business advertises.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0].handler_id',
        ]], $checkout['messages']);
        self::assertCount(1, $cartLoadRoute->loadedTokens, 'The cart is not loaded again when nothing was switched');
    }

    #[Test]
    public function testAnInstrumentTheApplierRefusesKeepsTheCheckoutOpenWithTheRefusal(): void
    {
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willThrowException(CompletionPaymentException::paymentMethodUnavailable(self::ADVERTISED_HANDLER_ID));
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID));

        $checkout = $adapter->createCheckout(
            new CheckoutCreateRequest([new UcpLineItem('product-a', 'product-a', 0.0, 1)], payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The payment handler "com.shopware.invoice" has no payment method that is available to this checkout.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ]], $checkout['messages']);
    }

    #[Test]
    public function testTheContextTheApplierReturnsLoadsTheCartOfTheCheckout(): void
    {
        $switchedContext = $this->createMock(SalesChannelContext::class);
        $switchedContext->method('getToken')->willReturn('switched-context-token');
        $switchedContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willReturn($switchedContext);
        $cartLoadRoute = new RecordingCartLoadRoute($this->cart());
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID), null, $cartLoadRoute);

        $adapter->createCheckout(
            new CheckoutCreateRequest([new UcpLineItem('product-a', 'product-a', 0.0, 1)], payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        );

        self::assertSame([self::OPEN_CHECKOUT_ID, 'switched-context-token'], $cartLoadRoute->loadedTokens);
    }

    #[Test]
    public function testTheValidationExceptionOfAReplacementApplierKeepsTheCheckoutOpenWithTheRefusal(): void
    {
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID));

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::OPEN_CHECKOUT_ID, [new UcpLineItem('product-a', 'product-a', 0.0, 1)], new Buyer(email: 'buyer@example.com'), payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::ReadyForComplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The instrument is not accepted for this buyer.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ]], $checkout['messages']);
    }

    #[Test]
    public function testACheckoutWithoutInstrumentLeavesThePaymentMethodAlone(): void
    {
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->expects(static::never())->method('apply');
        $adapter = $this->openCheckoutAdapter($this->openCheckoutContext(), $this->paymentSelectorApplying($applier, self::ADVERTISED_HANDLER_ID));

        $adapter->createCheckout(
            new CheckoutCreateRequest([new UcpLineItem('product-a', 'product-a', 0.0, 1)]),
            $this->completionRequestContext(),
        );
    }

    #[Test]
    public function testCompletingAPlacedOrderWhosePaymentFailedRetriesThePayment(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::once())->method('changePaymentMethod')->with(self::PLACED_ORDER_ID);
        $paymentGateway->expects(static::once())->method('pay')->willReturn(null);

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame(self::PLACED_ORDER_ID, $checkout->order?->id);
    }

    #[Test]
    public function testCompletingAPlacedOrderAwaitingTheBuyerReplaysTheRedirectWithoutRetrying(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::PAYMENT_REDIRECT_URL],
            $this->orderWithTransaction(OrderTransactionStates::STATE_OPEN),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::RequiresEscalation->value, $checkout['status']);
        self::assertSame(self::PAYMENT_REDIRECT_URL, $checkout['continue_url']);
        self::assertSame(['payment_requires_buyer_input'], array_column($checkout['messages'], 'code'));
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testCompletingAPlacedOrderWhosePaymentFailedWithARefusedInstrumentAnswersTheRefusalWithoutRetrying(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', 'com.example.deactivated')]),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The payment handler "com.example.deactivated" is not one this business advertises.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0].handler_id',
        ]], $checkout['messages']);
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testCompletingAPlacedOrderThatCannotBePaidAgainReplaysTheFailureWithoutGateOrRetry(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value, CompletionPaymentResult::SESSION_RETRYABLE_KEY => false],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', 'com.example.deactivated')]),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([['payment_failed', 'unrecoverable']], array_map(
            static fn (array $message): array => [$message['code'], $message['severity']],
            $checkout['messages'],
        ));
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testCompletingAPlacedOrderPaidSinceItsFailureReplaysItAsCompleted(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_PAID),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame(self::PLACED_ORDER_ID, $checkout->order?->id);
    }

    #[Test]
    public function testReadingAPlacedOrderWithAFailedTransactionAnswersIncomplete(): void
    {
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::PAYMENT_REDIRECT_URL],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
        );

        $checkout = $adapter->getCheckout(self::PLACED_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame(['payment_failed'], array_column($checkout['messages'], 'code'));
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testUpdatingAPlacedOrderWhosePaymentFailedWithAUsableInstrumentReadsReadyForCompleteAndIsStored(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');
        $savedSessions = [];

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $paymentGateway,
            $this->recordingPlacedOrderPersister(['status' => CheckoutStatus::Incomplete->value], $savedSessions),
        );

        $checkout = $adapter->updateCheckout($this->placedOrderUpdate(), $this->completionRequestContext())->toArray();

        self::assertSame(CheckoutStatus::ReadyForComplete->value, $checkout['status']);
        self::assertSame([], $checkout['messages']);
        self::assertArrayNotHasKey('order', $checkout);
        self::assertContains(CheckoutStatus::ReadyForComplete->value, array_column($savedSessions, 'status'));
    }

    #[Test]
    public function testAPlacedOrderMarkedReadyIsReadAsReadyForCompleteUntilTheAgentCompletes(): void
    {
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::ReadyForComplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
        );

        $checkout = $adapter->getCheckout(self::PLACED_CHECKOUT_ID, new RequestContext('shop.example'))->toArray();

        self::assertSame(CheckoutStatus::ReadyForComplete->value, $checkout['status']);
        self::assertSame([], $checkout['messages']);
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testCompletingAPlacedOrderMarkedReadyPaysItAgain(): void
    {
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::once())->method('changePaymentMethod')->with(self::PLACED_ORDER_ID);
        $paymentGateway->expects(static::once())->method('pay')->willReturn(null);

        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::ReadyForComplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $paymentGateway,
        );

        $checkout = $adapter->completeCheckoutFromRequest(
            new CheckoutCompleteRequest(self::PLACED_CHECKOUT_ID, [new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)]),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame(self::PLACED_ORDER_ID, $checkout->order?->id);
    }

    #[Test]
    public function testUpdatingAPlacedOrderThatCrashedDuringThePaymentIsAccepted(): void
    {
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::CompleteInProgress->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_OPEN),
            $this->createMock(OrderPaymentGatewayInterface::class),
        );

        $checkout = $adapter->updateCheckout($this->placedOrderUpdate(), $this->completionRequestContext());

        self::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
    }

    #[Test]
    public function testUpdatingAPlacedOrderWithoutLineItemsLeavesTheOrderAsItIs(): void
    {
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
        );

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::PLACED_CHECKOUT_ID, [], payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
    }

    /**
     * @return iterable<string, array{list<UcpLineItem>}>
     */
    public static function changedLineItemsProvider(): iterable
    {
        yield 'another quantity' => [[new UcpLineItem('product-a', 'product-a', 10.0, 2)]];
        yield 'another product' => [[new UcpLineItem('product-b', 'product-b', 10.0, 1)]];
        yield 'an additional product' => [[new UcpLineItem('product-a', 'product-a', 10.0, 1), new UcpLineItem('product-b', 'product-b', 10.0, 1)]];
    }

    /**
     * @param list<UcpLineItem> $lineItems
     */
    #[DataProvider('changedLineItemsProvider')]
    #[Test]
    public function testUpdatingAPlacedOrderWithOtherLineItemsKeepsItsStatusWithARecoverableRefusal(array $lineItems): void
    {
        $savedSessions = [];
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister(['status' => CheckoutStatus::Incomplete->value], $savedSessions),
        );

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::PLACED_CHECKOUT_ID, $lineItems, payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The order is placed, so only its payment can change. Send the line items of the order, or none.',
            'severity' => 'recoverable',
            'code' => 'order_placed',
            'path' => '$.line_items',
        ]], $checkout['messages']);
        self::assertSame([], $savedSessions, 'A refused update stores nothing');
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testUpdatingAPlacedOrderRepeatingItsLineItemsInAnotherOrderIsAccepted(): void
    {
        $order = $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED);
        $order->setLineItems(new OrderLineItemCollection([
            $this->orderProductLineItem('line-a', 'product-a', 1),
            $this->orderProductLineItem('line-b', 'product-b', 3),
        ]));
        $adapter = $this->placedOrderAdapter(['status' => CheckoutStatus::Incomplete->value], $order, $this->createMock(OrderPaymentGatewayInterface::class));

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::PLACED_CHECKOUT_ID, [new UcpLineItem('product-b', 'product-b', 10.0, 3), new UcpLineItem('product-a', 'product-a', 10.0, 1)], payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID)),
            $this->completionRequestContext(),
        );

        self::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
    }

    #[Test]
    public function testUpdatingAPlacedOrderWithAnInstrumentTheProfileDoesNotAdvertiseKeepsItsStatusWithTheRefusal(): void
    {
        $savedSessions = [];
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister(['status' => CheckoutStatus::Incomplete->value], $savedSessions),
        );

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::PLACED_CHECKOUT_ID, [new UcpLineItem('product-a', 'product-a', 10.0, 1)], payment: new PaymentInstrument('invoice', 'com.example.deactivated')),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => 'The payment handler "com.example.deactivated" is not one this business advertises.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0].handler_id',
        ]], $checkout['messages']);
        self::assertSame([], $savedSessions);
        self::assertArrayNotHasKey('order', $checkout);
    }

    #[Test]
    public function testUpdatingAPlacedOrderWithAPaymentMethodTheBuyerMayNotUseKeepsItsStatusWithTheRefusal(): void
    {
        $this->isPaymentMethodAvailable = false;
        $savedSessions = [];
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister(['status' => CheckoutStatus::Incomplete->value], $savedSessions),
        );

        $checkout = $adapter->updateCheckout($this->placedOrderUpdate(), $this->completionRequestContext())->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([['payment_failed', 'recoverable', '$.payment.instruments[0]']], array_map(
            static fn (array $message): array => [$message['code'], $message['severity'], $message['path']],
            $checkout['messages'],
        ));
        self::assertSame([], $savedSessions);
    }

    #[Test]
    public function testUpdatingAPlacedOrderWhereNoAdvertisedHandlerCanPaySavesTheFailureAsUnrecoverable(): void
    {
        $savedSessions = [];
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister(['status' => CheckoutStatus::Incomplete->value], $savedSessions),
            placedOrderGate: $this->placedOrderGateOnShopware('6.7.13.0', canBePaidAgain: false),
        );

        $checkout = $adapter->updateCheckout($this->placedOrderUpdate(), $this->completionRequestContext())->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([['payment_failed', 'unrecoverable', '$.payment.instruments']], array_map(
            static fn (array $message): array => [$message['code'], $message['severity'], $message['path']],
            $checkout['messages'],
        ));
        self::assertNotSame([], $savedSessions);
        foreach ($savedSessions as $savedSession) {
            self::assertSame(CheckoutStatus::Incomplete->value, $savedSession['status']);
            self::assertFalse($savedSession[CompletionPaymentResult::SESSION_RETRYABLE_KEY]);
        }
    }

    #[Test]
    public function testUpdatingAPlacedOrderWithoutAnInstrumentKeepsItsStatusWithTheRefusal(): void
    {
        $adapter = $this->placedOrderAdapter(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->orderWithTransaction(OrderTransactionStates::STATE_FAILED),
            $this->createMock(OrderPaymentGatewayInterface::class),
        );

        $checkout = $adapter->updateCheckout(
            new CheckoutUpdateRequest(self::PLACED_CHECKOUT_ID, [new UcpLineItem('product-a', 'product-a', 10.0, 1)]),
            $this->completionRequestContext(),
        )->toArray();

        self::assertSame(CheckoutStatus::Incomplete->value, $checkout['status']);
        self::assertSame([['payment_failed', 'recoverable', '$.payment.instruments']], array_map(
            static fn (array $message): array => [$message['code'], $message['severity'], $message['path']],
            $checkout['messages'],
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function placedOrderThatCannotBePaidAgainProvider(): iterable
    {
        yield 'the payment failed for good' => [['status' => 'incomplete', CompletionPaymentResult::SESSION_RETRYABLE_KEY => false], OrderTransactionStates::STATE_FAILED];
        yield 'the buyer has to finish the payment at the provider' => [['status' => 'requires_escalation', CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::PAYMENT_REDIRECT_URL], OrderTransactionStates::STATE_OPEN];
        yield 'the order is paid' => [['status' => 'completed'], OrderTransactionStates::STATE_PAID];
        yield 'the order is paid although the session reads incomplete' => [['status' => 'incomplete'], OrderTransactionStates::STATE_PAID];
    }

    /**
     * @param array<string, mixed> $sessionMetadata
     */
    #[DataProvider('placedOrderThatCannotBePaidAgainProvider')]
    #[Test]
    public function testUpdatingAPlacedOrderThatCannotBePaidAgainIsRefused(array $sessionMetadata, string $transactionState): void
    {
        $savedSessions = [];
        $adapter = $this->placedOrderAdapter(
            $sessionMetadata,
            $this->orderWithTransaction($transactionState),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister($sessionMetadata, $savedSessions),
        );

        $this->expectExceptionObject(new ValidationException('Completed checkout sessions cannot be updated.'));

        try {
            $adapter->updateCheckout($this->placedOrderUpdate(), $this->completionRequestContext());
        } finally {
            self::assertSame([], $savedSessions);
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function completedOrderIdOfPlacedCheckoutProvider(): iterable
    {
        yield 'a completion row' => [self::PLACED_ORDER_ID];
        yield 'only the legacy session metadata' => [null];
    }

    #[DataProvider('completedOrderIdOfPlacedCheckoutProvider')]
    #[Test]
    public function testCancellingACheckoutWithAPlacedOrderIsRefusedAndSavesNothing(?string $completionRowOrderId): void
    {
        $savedSessions = [];
        $sessionMetadata = ['status' => 'completed'];
        $adapter = $this->placedOrderAdapter(
            $sessionMetadata,
            $this->orderWithTransaction(OrderTransactionStates::STATE_OPEN),
            $this->createMock(OrderPaymentGatewayInterface::class),
            $this->recordingPlacedOrderPersister($sessionMetadata, $savedSessions),
            $completionRowOrderId,
        );

        $this->expectExceptionObject(new ValidationException('Checkout sessions with a placed order cannot be canceled.'));

        try {
            $adapter->cancelCheckout(self::PLACED_CHECKOUT_ID, $this->completionRequestContext());
        } finally {
            self::assertSame([], $savedSessions);
        }
    }

    private function placedOrderUpdate(): CheckoutUpdateRequest
    {
        return new CheckoutUpdateRequest(
            self::PLACED_CHECKOUT_ID,
            [new UcpLineItem('product-a', 'product-a', 10.0, 1)],
            payment: new PaymentInstrument('invoice', self::ADVERTISED_HANDLER_ID),
        );
    }

    /**
     * @param array<string, mixed>       $sessionMetadata
     * @param list<array<string, mixed>> $savedSessions   receives the checkout session of every save
     */
    private function recordingPlacedOrderPersister(array $sessionMetadata, array &$savedSessions): SalesChannelContextPersister
    {
        $persister = $this->placedOrderPersister($sessionMetadata);
        $persister->method('save')->willReturnCallback(static function (string $token, array $parameters) use (&$savedSessions): void {
            $savedSessions[] = $parameters['swagAgenticCommerce']['ucpCheckout'];
        });

        return $persister;
    }

    /**
     * @param array<string, mixed> $sessionMetadata
     */
    private function placedOrderPersister(array $sessionMetadata): SalesChannelContextPersister&MockObject
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn(['swagAgenticCommerce' => ['ucpCheckout' => [
            ...$sessionMetadata,
            'orderId' => self::PLACED_ORDER_ID,
            'shopwareContextToken' => 'rotated-shopware-context-token',
        ]]]);

        return $persister;
    }

    private function orderProductLineItem(string $id, string $productId, int $quantity): OrderLineItemEntity
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId($id);
        $lineItem->setIdentifier($productId);
        $lineItem->setReferencedId($productId);
        $lineItem->setType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setQuantity($quantity);
        $lineItem->setLabel($productId);

        return $lineItem;
    }

    /**
     * @param array<string, mixed> $sessionMetadata
     */
    private function placedOrderAdapter(array $sessionMetadata, OrderEntity $order, OrderPaymentGatewayInterface $paymentGateway, ?SalesChannelContextPersister $persister = null, ?string $completionRowOrderId = self::PLACED_ORDER_ID, ?PlacedOrderPaymentMethodGate $placedOrderGate = null): ShopwareCheckoutAdapter
    {
        $persister ??= $this->placedOrderPersister($sessionMetadata);

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $checkoutSessionManager = new CheckoutSessionManager($checkoutSessionStore);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($this->openCheckoutContext()),
            $persister,
        );
        $continueUrlBuilder = $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn($completionRowOrderId);
        $completionStore->expects(static::never())->method('complete');

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('getOrderForSalesChannelContext')->willReturn($order);
        $orderGateway->expects(static::never())->method('placeOrder');

        $checkoutCompleter = new CheckoutCompleter(
            $orderGateway,
            new ShopwareDataMapper(),
            $this->createMock(GuestCustomerContextProvisionerInterface::class),
            $this->ucpConfigService(),
            $checkoutSessionManager,
            $completionStore,
            new LockFactory(new InMemoryStore()),
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new CompletionPaymentProcessor($this->passThroughApplier(), $paymentGateway, new RecordingCartLoadRoute($this->cart()), $this->createMock(AbstractContextSwitchRoute::class), $this->placedOrderGate()),
            $this->legalLinkBuilder(),
        );

        return new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            $orderGateway,
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            $checkoutSessionManager,
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $continueUrlBuilder,
            $checkoutCompleter,
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelectorGatedBy($placedOrderGate ?? $this->placedOrderGate(), $this->passThroughApplier(), self::ADVERTISED_HANDLER_ID),
        );
    }

    private function orderWithTransaction(string $transactionState): OrderEntity
    {
        $state = new StateMachineStateEntity();
        $state->setId('66666666666666666666666666666666');
        $state->setTechnicalName($transactionState);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('77777777777777777777777777777777');
        $transaction->setCreatedAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
        $transaction->setStateMachineState($state);

        $order = $this->order(self::PLACED_ORDER_ID);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));
        $order->setLineItems(new OrderLineItemCollection([$this->orderProductLineItem('line-a', 'product-a', 1)]));

        return $order;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function assertRefusedCheckout(array $checkout, string $expectedContent, string $expectedPath, CheckoutStatus $expectedStatus = CheckoutStatus::Incomplete): void
    {
        self::assertSame($expectedStatus->value, $checkout['status']);
        self::assertSame([[
            'type' => 'error',
            'content' => $expectedContent,
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => $expectedPath,
        ]], $checkout['messages']);
        self::assertCount(1, $checkout['line_items']);
        self::assertSame([
            ['type' => 'continue', 'url' => 'https://shop.example/checkout/'.self::OPEN_CHECKOUT_ID, 'title' => 'Continue checkout'],
            ...$this->legalLinkArrays(),
        ], $checkout['links']);
        self::assertArrayNotHasKey('order', $checkout);
    }

    private function checkoutCompleter(AbstractCompletionPaymentApplier $applier): CheckoutCompleter
    {
        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getToken')->willReturn(self::OPEN_CHECKOUT_ID);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $guestCustomerContextProvisioner = $this->createMock(GuestCustomerContextProvisionerInterface::class);
        $guestCustomerContextProvisioner->method('ensureGuestCustomer')->willReturn($customerContext);

        $persister = $this->createMock(SalesChannelContextPersister::class);

        return new CheckoutCompleter(
            $this->createMock(OrderGatewayInterface::class),
            new ShopwareDataMapper(),
            $guestCustomerContextProvisioner,
            $this->ucpConfigService(),
            new CheckoutSessionManager(new CheckoutSessionStore($persister)),
            $this->createMock(CheckoutCompletionStoreInterface::class),
            new LockFactory(new InMemoryStore()),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new CompletionPaymentProcessor($applier, $this->createMock(OrderPaymentGatewayInterface::class), new RecordingCartLoadRoute($this->cart()), $this->createMock(AbstractContextSwitchRoute::class), $this->placedOrderGate()),
            $this->legalLinkBuilder(),
        );
    }

    private function placedOrderGate(): PlacedOrderPaymentMethodGate
    {
        return $this->placedOrderGateOnShopware('6.6.10.20');
    }

    private function paymentSelector(string ...$advertisedHandlerIds): CheckoutPaymentSelector
    {
        return $this->paymentSelectorApplying($this->passThroughApplier(), ...$advertisedHandlerIds);
    }

    private function paymentSelectorApplying(AbstractCompletionPaymentApplier $applier, string ...$advertisedHandlerIds): CheckoutPaymentSelector
    {
        return $this->paymentSelectorGatedBy($this->placedOrderGate(), $applier, ...$advertisedHandlerIds);
    }

    private function paymentSelectorGatedBy(PlacedOrderPaymentMethodGate $placedOrderGate, AbstractCompletionPaymentApplier $applier, string ...$advertisedHandlerIds): CheckoutPaymentSelector
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$advertisedHandlerIds);

        return new CheckoutPaymentSelector(
            new CompletionPaymentGate(new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder)),
            $applier,
            $placedOrderGate,
        );
    }

    private function passThroughApplier(): AbstractCompletionPaymentApplier
    {
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willReturnCallback(function (?PaymentInstrument $instrument, SalesChannelContext $context): SalesChannelContext {
            if (!$this->isPaymentMethodAvailable) {
                throw CompletionPaymentException::paymentMethodUnavailable($instrument->handlerId ?? '');
            }

            return $context;
        });

        return $applier;
    }

    private function completionRequestContext(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'),
        );
    }

    private function ucpConfigService(?string $continueUrlTemplate = null): UcpConfigService
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn(new UcpConfig(continueUrlTemplate: $continueUrlTemplate));

        return new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class));
    }

    private function openCheckoutContext(bool $withLegalLinkDomain = true, string $token = self::OPEN_CHECKOUT_ID): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getToken')->willReturn($token);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getCurrency')->willReturn($currency);
        if ($withLegalLinkDomain) {
            $this->withLegalLinkDomain($salesChannelContext);
        }

        return $salesChannelContext;
    }

    private function legalLinkBuilder(): CheckoutLegalLinkBuilder
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturnMap([
            ['core.basicInformation.tosPage', self::SALES_CHANNEL_ID, self::TOS_PAGE_ID],
            ['core.basicInformation.privacyPage', self::SALES_CHANNEL_ID, self::PRIVACY_PAGE_ID],
        ]);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/page/cms/'.$parameters['id']);

        $cmsPageRepository = $this->createMock(EntityRepository::class);
        $cmsPageRepository->method('searchIds')->willReturnCallback(static function (Criteria $criteria, Context $context): IdSearchResult {
            /** @var list<string> $ids */
            $ids = $criteria->getIds();
            $found = [];
            foreach ($ids as $id) {
                $found[$id] = ['primaryKey' => $id, 'data' => ['id' => $id]];
            }

            return new IdSearchResult(\count($found), $found, $criteria, $context);
        });

        return new CheckoutLegalLinkBuilder(
            $router,
            $systemConfigService,
            new SalesChannelBaseUrlResolver($this->createMock(EntityRepository::class)),
            $cmsPageRepository,
        );
    }

    /**
     * Only a context with a storefront domain gets legal links, which proves which context the adapter used.
     */
    private function withLegalLinkDomain(SalesChannelContext&MockObject $context): void
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId(self::LEGAL_LINK_DOMAIN_ID);
        $domain->setUrl('https://shop.example');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setTypeId(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
        $salesChannel->setDomains(new SalesChannelDomainCollection([$domain]));

        $context->method('getDomainId')->willReturn(self::LEGAL_LINK_DOMAIN_ID);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
    }

    /**
     * @return list<Link>
     */
    private function legalLinks(): array
    {
        return [
            new Link('terms_of_service', 'https://shop.example/page/cms/'.self::TOS_PAGE_ID),
            new Link('privacy_policy', 'https://shop.example/page/cms/'.self::PRIVACY_PAGE_ID),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function legalLinkArrays(): array
    {
        return array_map(static fn (Link $link): array => $link->toArray(), $this->legalLinks());
    }

    private function cart(): Cart
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();

        $lineItem = (new LineItem('product-a', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-a', 1))->setStackable(true)->setRemovable(true);
        $lineItem->setLabel('Nice Shirt');
        $lineItem->setPrice(new CalculatedPrice(10.0, 10.0, $taxes, $taxRules));

        $cart = new Cart(self::OPEN_CHECKOUT_ID);
        $cart->setLineItems(new LineItemCollection([$lineItem]));
        $cart->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));

        return $cart;
    }

    #[Test]
    public function testCompletedCheckoutRequiresStoredShopwareContextToken(): void
    {
        $checkoutId = 'checkout-context-token';
        $salesChannelId = '22222222222222222222222222222222';

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::never())->method('get');

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::once())
            ->method('load')
            ->with($checkoutId, $salesChannelId, null)
            ->willReturn([
                'swagAgenticCommerce' => [
                    'ucpCheckout' => [
                        'status' => CheckoutStatus::Completed->value,
                        'orderId' => '99999999999999999999999999999999',
                    ],
                ],
            ]);

        $checkoutSessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $this->createMock(AbstractOrderRoute::class), $checkoutSessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $checkoutSessionStore,
            new CheckoutSessionManager($checkoutSessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($checkoutSessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
            $this->paymentSelector(),
        );

        $this->expectExceptionObject(new ValidationException('Completed checkout session is missing its Shopware context token.'));

        $adapter->getCheckout($checkoutId, new RequestContext('shop.example'));
    }

    private function order(string $orderId): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, $taxes, $taxRules));

        return $order;
    }

    private function continueUrlBuilder(?string $template = null): CheckoutContinueUrlBuilder
    {
        return new CheckoutContinueUrlBuilder($this->ucpConfigService($template));
    }

    private function domainResolver(string $salesChannelId): SalesChannelDomainResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('11111111111111111111111111111111');
        $domain->setSalesChannelId($salesChannelId);
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

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
