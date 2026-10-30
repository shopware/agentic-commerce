<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilderInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutLegalLinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManagerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\UnappliedCompletionPayment;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/**
 * @internal
 */
#[CoversClass(CheckoutCompleter::class)]
final class CheckoutCompleterTest extends TestCase
{
    private const CHECKOUT_ID = 'checkout-token';
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const ORDER_ID = '00000000000000000000000000000002';
    private const ROTATED_CONTEXT_TOKEN = 'rotated-context-token';
    private const LOCK_KEY = 'ucp.checkout.completion.'.self::CHECKOUT_ID.'.'.self::SALES_CHANNEL_ID;
    private const DOMAIN_ID = '00000000000000000000000000000003';
    private const TOS_PAGE_ID = '00000000000000000000000000000004';
    private const PRIVACY_PAGE_ID = '00000000000000000000000000000005';

    #[Test]
    public function testAlreadyCompletedBeforeLockAcquireReplays(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(self::ORDER_ID);
        $completionStore->expects(static::never())->method('complete');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setCurrency($currency);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())->method('getOrder')->with(self::ORDER_ID)->willReturn($order);
        $orderGateway->expects(static::never())->method('placeOrder');

        $expectedCheckout = $this->uninitialized(Checkout::class);

        $mapper = new class($expectedCheckout) implements ShopwareDataMapperInterface {
            /** @var list<Link>|null */
            public ?array $legalLinks = null;

            public function __construct(private readonly Checkout $checkout)
            {
            }

            public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = []): Checkout
            {
                $this->legalLinks = $legalLinks;

                return $this->checkout;
            }

            public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView
            {
                throw new \BadMethodCallException('Not called in this test.');
            }
        };

        $continueUrlBuilder = new class implements CheckoutContinueUrlBuilderInterface {
            public function build(string $checkoutId, string $salesChannelId): string
            {
                return 'https://example.com/continue';
            }
        };

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $this->withLegalLinkDomain($salesChannelContext);

        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $mapper,
            $this->nullProvisioner(),
            $this->uninitialized(UcpConfigService::class),
            $this->nullSessionManager(),
            $completionStore,
            $lockFactory,
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new UnappliedCompletionPayment(),
            $this->legalLinkBuilder(),
        );

        $result = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));

        static::assertSame($expectedCheckout, $result);
        static::assertEquals($this->legalLinks(), $mapper->legalLinks);

        // Lock was never acquired so it remains acquirable
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false));
    }

    #[Test]
    public function testConcurrentRequestThrowsValidationException(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $orderGateway->expects(static::never())->method('getOrder');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);

        // Simulate a concurrent request holding the lock
        $heldLock = $lockFactory->createLock(self::LOCK_KEY);
        $heldLock->acquire(false);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $this->uninitialized(ShopwareDataMapper::class),
            $this->nullProvisioner(),
            $this->uninitialized(UcpConfigService::class),
            $this->nullSessionManager(),
            $completionStore,
            $lockFactory,
            $this->uninitialized(CheckoutContinueUrlBuilder::class),
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new UnappliedCompletionPayment(),
            $this->legalLinkBuilder(),
        );

        $this->expectExceptionObject(new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.'));

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));
    }

    #[Test]
    public function testAcquiredLockPlacesOrderAndCompletesStore(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);
        $completionStore->expects(static::once())->method('complete')->with(self::CHECKOUT_ID, self::ORDER_ID);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $customerContext->method('getCurrency')->willReturn($currency);
        $this->withLegalLinkDomain($customerContext);

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())->method('placeOrder')->willReturn($order);
        $orderGateway->expects(static::never())->method('getOrder');

        $saveCalled = 0;
        $sessionManager = new class($saveCalled) implements CheckoutSessionManagerInterface {
            public function __construct(private int &$saveCalled)
            {
            }

            public function buyer(array $metadata): ?Buyer
            {
                return null;
            }

            public function guestShippingAddress(array $metadata): ?array
            {
                return null;
            }

            public function guestAddress(array $metadata): ?array
            {
                return null;
            }

            public function save(SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null): void
            {
            }

            public function saveForCheckoutId(string $checkoutId, SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null): void
            {
                ++$this->saveCalled;
            }
        };

        $expectedCheckout = $this->uninitialized(Checkout::class);

        $mapper = new class($expectedCheckout) implements ShopwareDataMapperInterface {
            /** @var list<Link>|null */
            public ?array $legalLinks = null;

            public function __construct(private readonly Checkout $checkout)
            {
            }

            public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = []): Checkout
            {
                $this->legalLinks = $legalLinks;

                return $this->checkout;
            }

            public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView
            {
                throw new \BadMethodCallException('Not called in this test.');
            }
        };

        $provisioner = new class($customerContext) implements GuestCustomerContextProvisionerInterface {
            public function __construct(private readonly SalesChannelContext $customerContext)
            {
            }

            public function ensureGuestCustomer(SalesChannelContext $context, ?Buyer $buyer, ?array $guestAddress = null, ?array $guestShippingAddress = null): SalesChannelContext
            {
                return $this->customerContext;
            }
        };

        $configService = $this->nullConfigService();

        $continueUrlBuilder = new class implements CheckoutContinueUrlBuilderInterface {
            public function build(string $checkoutId, string $salesChannelId): string
            {
                return 'https://example.com/continue';
            }
        };

        $orderWebhookPublisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $orderWebhookPublisher->expects(static::never())->method('publish');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $mapper,
            $provisioner,
            $configService,
            $sessionManager,
            $completionStore,
            $lockFactory,
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $orderWebhookPublisher,
            new OrderPermalinkBuilder(),
            new UnappliedCompletionPayment(),
            $this->legalLinkBuilder(),
        );

        $result = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));

        static::assertSame($expectedCheckout, $result);
        static::assertEquals($this->legalLinks(), $mapper->legalLinks);
        static::assertSame(1, $saveCalled, 'sessionManager->saveForCheckoutId() must be called exactly once');

        // Lock released via finally — a new acquire must succeed
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'Lock must be released after successful completion');
    }

    #[Test]
    public function testOrderPlacementExceptionReleasesLock(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);
        $completionStore->expects(static::never())->method('complete');

        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('placeOrder')->willThrowException(new \RuntimeException('Order placement failed.'));
        $orderGateway->expects(static::never())->method('getOrder');

        $provisioner = new class($customerContext) implements GuestCustomerContextProvisionerInterface {
            public function __construct(private readonly SalesChannelContext $customerContext)
            {
            }

            public function ensureGuestCustomer(SalesChannelContext $context, ?Buyer $buyer, ?array $guestAddress = null, ?array $guestShippingAddress = null): SalesChannelContext
            {
                return $this->customerContext;
            }
        };

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $this->uninitialized(ShopwareDataMapper::class),
            $provisioner,
            $this->nullConfigService(),
            $this->nullSessionManager(),
            $completionStore,
            $lockFactory,
            $this->uninitialized(CheckoutContinueUrlBuilder::class),
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new UnappliedCompletionPayment(),
            $this->legalLinkBuilder(),
        );

        try {
            $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));
            static::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            static::assertSame('Order placement failed.', $e->getMessage());
        }

        // Lock released via finally — a new acquire must succeed
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'Lock must be released after order placement failure');
    }

    #[Test]
    public function testCartIsRetokenizedToTheProvisionedCustomerContext(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        // Guest registration rotates the Shopware context token and migrates the persisted cart to it.
        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $customerContext->method('getCurrency')->willReturn($currency);
        $customerContext->method('getToken')->willReturn(self::ROTATED_CONTEXT_TOKEN);

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);

        $orderedCart = null;
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())
            ->method('placeOrder')
            ->willReturnCallback(static function (Cart $cart) use (&$orderedCart, $order): OrderEntity {
                $orderedCart = $cart;

                return $order;
            });

        $provisioner = new class($customerContext) implements GuestCustomerContextProvisionerInterface {
            public function __construct(private readonly SalesChannelContext $customerContext)
            {
            }

            public function ensureGuestCustomer(SalesChannelContext $context, ?Buyer $buyer, ?array $guestAddress = null, ?array $guestShippingAddress = null): SalesChannelContext
            {
                return $this->customerContext;
            }
        };

        $mapper = new class($this->uninitialized(Checkout::class)) implements ShopwareDataMapperInterface {
            public function __construct(private readonly Checkout $checkout)
            {
            }

            public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = []): Checkout
            {
                return $this->checkout;
            }

            public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView
            {
                throw new \BadMethodCallException('Not called in this test.');
            }
        };

        $continueUrlBuilder = new class implements CheckoutContinueUrlBuilderInterface {
            public function build(string $checkoutId, string $salesChannelId): string
            {
                return 'https://example.com/continue';
            }
        };

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $salesChannelContext->method('getToken')->willReturn(self::CHECKOUT_ID);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $mapper,
            $provisioner,
            $this->nullConfigService(),
            $this->nullSessionManager(),
            $completionStore,
            new LockFactory(new InMemoryStore()),
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            new UnappliedCompletionPayment(),
            $this->legalLinkBuilder(),
        );

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));

        static::assertInstanceOf(Cart::class, $orderedCart);
        static::assertSame(
            self::ROTATED_CONTEXT_TOKEN,
            $orderedCart->getToken(),
            'The ordered cart must carry the post-registration context token, otherwise the order route cannot find its storage entry',
        );
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
     * Only a context with a storefront domain gets legal links, which proves which context the completer used.
     */
    private function withLegalLinkDomain(SalesChannelContext&MockObject $context): void
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId(self::DOMAIN_ID);
        $domain->setUrl('https://shop.example');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setDomains(new SalesChannelDomainCollection([$domain]));

        $context->method('getDomainId')->willReturn(self::DOMAIN_ID);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
    }

    private function nullProvisioner(): GuestCustomerContextProvisionerInterface
    {
        return new class implements GuestCustomerContextProvisionerInterface {
            public function ensureGuestCustomer(SalesChannelContext $context, ?Buyer $buyer, ?array $guestAddress = null, ?array $guestShippingAddress = null): SalesChannelContext
            {
                throw new \BadMethodCallException('Not called in this test.');
            }
        };
    }

    private function nullSessionManager(): CheckoutSessionManagerInterface
    {
        return new class implements CheckoutSessionManagerInterface {
            public function buyer(array $metadata): ?Buyer
            {
                return null;
            }

            public function guestShippingAddress(array $metadata): ?array
            {
                return null;
            }

            public function guestAddress(array $metadata): ?array
            {
                return null;
            }

            public function save(SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null): void
            {
            }

            public function saveForCheckoutId(string $checkoutId, SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null): void
            {
            }
        };
    }

    private function nullConfigService(): UcpConfigService
    {
        return new UcpConfigService(
            new class implements UcpConfigRepositoryInterface {
                public function find(string $salesChannelId): ?UcpConfig
                {
                    return null;
                }

                public function findMany(array $salesChannelIds): array
                {
                    return [];
                }

                public function save(string $salesChannelId, UcpConfig $config): void
                {
                }
            },
            new class implements LegacyConfigStoreInterface {
                public function get(string $key, ?string $salesChannelId): mixed
                {
                    return null;
                }

                public function set(string $key, mixed $value, ?string $salesChannelId): void
                {
                }
            },
        );
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
