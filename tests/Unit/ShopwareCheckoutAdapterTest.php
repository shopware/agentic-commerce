<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(ShopwareCheckoutAdapter::class)]
final class ShopwareCheckoutAdapterTest extends TestCase
{
    private const SALES_CHANNEL_ID = '22222222222222222222222222222222';
    private const OPEN_CHECKOUT_ID = 'open-checkout-token';
    private const CONTINUE_URL_TEMPLATE = 'https://shop.example/checkout/{checkoutId}';
    private const LEGAL_LINK_DOMAIN_ID = '33333333333333333333333333333333';
    private const TOS_PAGE_ID = '44444444444444444444444444444444';
    private const PRIVACY_PAGE_ID = '55555555555555555555555555555555';

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
                \Symfony\Component\HttpFoundation\Request $_request,
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

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);
        $mapper = new ShopwareDataMapper();

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $sessionStore, new RequestStack()),
            $mapper,
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
        );

        $checkout = $adapter->getCheckout($checkoutId, new RequestContext('shop.example'));

        self::assertSame($checkoutId, $checkout->id);
        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
        self::assertSame($this->legalLinkArrays(), $checkout->toArray()['links']);
    }

    #[Test]
    public function testCompletingAnAlreadyCompletedCheckoutReplaysTheOrderWithLegalLinks(): void
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
            \Symfony\Component\HttpFoundation\Request $_request,
            SalesChannelContext $_context,
            Criteria $criteria,
        ) use ($order): OrderRouteResponse {
            $orders = new OrderCollection([$order]);

            return new OrderRouteResponse(new EntitySearchResult('order', 1, $orders, null, $criteria, Context::createDefaultContext()));
        });

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->with($checkoutId)->willReturn($orderId);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $sessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
        );

        $checkout = $adapter->completeCheckout($checkoutId, new RequestContext('shop.example'))->toArray();

        self::assertSame(CheckoutStatus::Completed->value, $checkout['status']);
        self::assertSame($orderId, $checkout['order']['id']);
        self::assertSame('https://shop.example/checkout/'.$checkoutId, $checkout['continue_url']);
        self::assertSame([
            ['type' => 'order', 'url' => 'https://shop.example/checkout/'.$checkoutId, 'title' => 'Order details'],
            ...$this->legalLinkArrays(),
        ], $checkout['links']);
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

    private function openCheckoutAdapter(SalesChannelContext $salesChannelContext): ShopwareCheckoutAdapter
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn([
            'swagAgenticCommerce' => ['ucpCheckout' => ['status' => CheckoutStatus::Incomplete->value]],
        ]);

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver(
            $this->domainResolver(self::SALES_CHANNEL_ID),
            new StaticSalesChannelContextService($salesChannelContext),
            $persister,
        );

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $cartGateway = new ShopwareCartGateway(
            $contextResolver,
            new RecordingCartLoadRoute($this->cart()),
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
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(self::CONTINUE_URL_TEMPLATE),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
        );
    }

    private function openCheckoutContext(bool $withLegalLinkDomain = true): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getToken')->willReturn(self::OPEN_CHECKOUT_ID);
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
            $found = [];
            foreach ($criteria->getIds() as $id) {
                \assert(\is_string($id));
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
            new Link('terms_of_service', 'https://shop.example/page/cms/'.self::TOS_PAGE_ID, 'Terms of Service'),
            new Link('privacy_policy', 'https://shop.example/page/cms/'.self::PRIVACY_PAGE_ID, 'Privacy Policy'),
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

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $this->createMock(AbstractOrderRoute::class), $sessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
            $this->legalLinkBuilder(),
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
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn(new UcpConfig(continueUrlTemplate: $template));

        return new CheckoutContinueUrlBuilder(new UcpConfigService(
            $repository,
            $this->createMock(LegacyConfigStoreInterface::class),
        ));
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
