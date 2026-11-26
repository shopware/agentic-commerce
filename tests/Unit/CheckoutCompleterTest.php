<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartLoadRoute;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilderInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutLegalLinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManagerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentProcessor;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisionerInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\OrderPaymentGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
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
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000006';
    private const DEEP_LINK_CODE = 'deep-link-code';
    private const PERMALINK_URL = 'https://shop.example/account/order/'.self::DEEP_LINK_CODE;
    private const PAYMENT_REDIRECT_URL = 'https://psp.example/pay/1';
    private const CONTINUE_URL = 'https://example.com/continue';

    #[Test]
    public function testAlreadyCompletedBeforeLockAcquireLeavesTheReplayToTheCaller(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(self::ORDER_ID);
        $completionStore->expects(static::never())->method('complete');

        $lockFactory = new LockFactory(new InMemoryStore());

        $result = $this->completerThatPlacesNoOrder($completionStore, $lockFactory)
            ->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete);

        static::assertNull($result);
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'The lock must not be taken for a completed checkout');
    }

    #[Test]
    public function testCompletedWhileWaitingForTheLockLeavesTheReplayToTheCaller(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturnOnConsecutiveCalls(null, self::ORDER_ID);
        $completionStore->expects(static::never())->method('complete');

        $lockFactory = new LockFactory(new InMemoryStore());

        $result = $this->completerThatPlacesNoOrder($completionStore, $lockFactory)
            ->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete);

        static::assertNull($result);
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'The lock must be released');
    }

    private function completerThatPlacesNoOrder(CheckoutCompletionStoreInterface $completionStore, LockFactory $lockFactory): CheckoutCompleter
    {
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $orderGateway->expects(static::never())->method('getOrder');

        return new CheckoutCompleter(
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
            $this->completionPayment(),
            $this->legalLinkBuilder(),
        );
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $salesChannelContext;
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
            $this->completionPayment(),
            $this->legalLinkBuilder(),
        );

        $this->expectExceptionObject(new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.'));

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'), CheckoutStatus::Incomplete);
    }

    #[Test]
    public function testAcquiredLockPlacesOrderAndCompletesStore(): void
    {
        $saveCount = 0;
        $saveCountAtCompletionRow = null;
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);
        $completionStore->expects(static::once())
            ->method('complete')
            ->with(self::CHECKOUT_ID, self::ORDER_ID)
            ->willReturnCallback(static function () use (&$saveCount, &$saveCountAtCompletionRow): void {
                $saveCountAtCompletionRow = $saveCount;
            });

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

        $sessionManager = new class($saveCount) implements CheckoutSessionManagerInterface {
            public function __construct(private int &$saveCount)
            {
            }

            public function load(string $checkoutId, string $salesChannelId): array
            {
                return [];
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

            public function saveForCheckoutId(string $checkoutId, SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null, ?CompletionPaymentResult $payment = null): void
            {
                ++$this->saveCount;
            }
        };

        $expectedCheckout = $this->uninitialized(Checkout::class);

        $mapper = new class($expectedCheckout) implements ShopwareDataMapperInterface {
            /** @var list<Link>|null */
            public ?array $legalLinks = null;

            public function __construct(private readonly Checkout $checkout)
            {
            }

            public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = [], ?CompletionPaymentResult $payment = null, ?array $messages = null): Checkout
            {
                $this->legalLinks = $legalLinks;

                return $this->checkout;
            }

            public function toCheckout(Cart $cart, SalesChannelContext $context, CheckoutStatus $status, ?Buyer $buyer = null, ?string $continueUrl = null, ?OrderEntity $order = null, array $legalLinks = [], array $messages = []): Checkout
            {
                throw new \BadMethodCallException('Not called in this test.');
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
            $this->completionPayment(),
            $this->legalLinkBuilder(),
        );

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'), CheckoutStatus::Incomplete);

        static::assertSame($expectedCheckout, $checkout);
        static::assertEquals($this->legalLinks(), $mapper->legalLinks);
        static::assertSame(2, $saveCount, 'The session is saved before the payment and again with its result');
        static::assertSame(1, $saveCountAtCompletionRow, 'The session must carry the rotated context token before the completion row exists');

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
            $this->completionPayment(),
            $this->legalLinkBuilder(),
        );

        try {
            $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'), CheckoutStatus::Incomplete);
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

            public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null, ?string $orderPermalinkUrl = null, array $legalLinks = [], ?CompletionPaymentResult $payment = null, ?array $messages = null): Checkout
            {
                return $this->checkout;
            }

            public function toCheckout(Cart $cart, SalesChannelContext $context, CheckoutStatus $status, ?Buyer $buyer = null, ?string $continueUrl = null, ?OrderEntity $order = null, array $legalLinks = [], array $messages = []): Checkout
            {
                throw new \BadMethodCallException('Not called in this test.');
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
            $this->completionPayment(),
            $this->legalLinkBuilder(),
        );

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'), CheckoutStatus::Incomplete);

        static::assertInstanceOf(Cart::class, $orderedCart);
        static::assertSame(
            self::ROTATED_CONTEXT_TOKEN,
            $orderedCart->getToken(),
            'The ordered cart must carry the post-registration context token, otherwise the order route cannot find its storage entry',
        );
    }

    #[Test]
    public function testThePaymentRunsAfterTheOrderIsTiedToTheCheckoutAndARedirectAnswersRequiresEscalation(): void
    {
        $instrument = new PaymentInstrument('card', 'com.example.psp');
        $customerContext = $this->customerContext();
        $calls = [];

        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::once())
            ->method('pay')
            ->with(self::ORDER_ID, $customerContext, $instrument, self::PERMALINK_URL, self::CONTINUE_URL)
            ->willReturnCallback(static function () use (&$calls): string {
                $calls[] = ['pay'];

                return self::PAYMENT_REDIRECT_URL;
            });

        $completer = $this->placingCompleter($this->orderGatewayPlacing($this->placedOrder()), $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext);

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, $instrument);

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::RequiresEscalation, $checkout->status);
        static::assertSame(self::PAYMENT_REDIRECT_URL, $checkout->continueUrl);
        static::assertNull($checkout->order);
        static::assertSame([
            ['session', CheckoutStatus::CompleteInProgress->value, []],
            ['completion row', self::ORDER_ID],
            ['pay'],
            ['session', CheckoutStatus::RequiresEscalation->value, [CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::PAYMENT_REDIRECT_URL]],
        ], $calls);
    }

    #[Test]
    public function testAFailedPaymentAnswersIncompleteAndKeepsTheCompletion(): void
    {
        $calls = [];
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->method('pay')->willThrowException(new \RuntimeException('Card declined.'));

        $completer = $this->placingCompleter($this->orderGatewayPlacing($this->placedOrder()), $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $this->customerContext());

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::Incomplete, $checkout->status);
        static::assertSame([['payment_failed', 'recoverable']], $this->messageCodes($checkout));
        static::assertNull($checkout->order);
        static::assertSame([
            ['session', CheckoutStatus::CompleteInProgress->value, []],
            ['completion row', self::ORDER_ID],
            ['session', CheckoutStatus::Incomplete->value, []],
        ], $calls);
    }

    #[Test]
    public function testAnOrderShopwareStoredDespiteAFailedPaymentIsKeptAndCannotBePaidAgain(): void
    {
        $calls = [];
        $customerContext = $this->customerContext();

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('placeOrder')->willThrowException(CartException::invalidPaymentButOrderStored(self::ORDER_ID));
        $orderGateway->expects(static::once())
            ->method('getOrderForSalesChannelContext')
            ->with(self::ORDER_ID, $customerContext)
            ->willReturn($this->placedOrder());

        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('pay');

        $completer = $this->placingCompleter($orderGateway, $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext);

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::Incomplete, $checkout->status);
        static::assertSame([['payment_failed', 'unrecoverable']], $this->messageCodes($checkout));
        static::assertSame([
            ['session', CheckoutStatus::Incomplete->value, [CompletionPaymentResult::SESSION_RETRYABLE_KEY => false]],
            ['completion row', self::ORDER_ID],
        ], $calls);
    }

    #[Test]
    public function testAnyOtherCartExceptionFromTheOrderPlacementPropagates(): void
    {
        $calls = [];
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('placeOrder')->willThrowException(CartException::invalidPaymentOrderNotStored(self::ORDER_ID));
        $orderGateway->expects(static::never())->method('getOrderForSalesChannelContext');

        $completer = $this->placingCompleter($orderGateway, $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $this->createMock(OrderPaymentGatewayInterface::class), $this->customerContext());

        try {
            $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, new PaymentInstrument('card', 'com.example.psp'));
            static::fail('The cart exception must propagate.');
        } catch (CartException $exception) {
            static::assertSame(CartException::CART_PAYMENT_INVALID_ORDER_CODE, $exception->getErrorCode());
        }

        static::assertSame([], $calls);
    }

    #[Test]
    public function testRetryPaymentPaysAFailedOrderAgainAndSavesTheNewStatus(): void
    {
        $instrument = new PaymentInstrument('card', 'com.example.psp');
        $context = $this->customerContext();
        $calls = [];

        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::once())->method('changePaymentMethod')->with(self::ORDER_ID, $context);
        $paymentGateway->expects(static::once())->method('pay')->with(self::ORDER_ID, $context, $instrument, self::PERMALINK_URL, self::CONTINUE_URL)->willReturn(null);

        $completionStore = $this->recordingCompletionStore($calls);
        $completionStore->expects(static::never())->method('complete');

        $lockFactory = new LockFactory(new InMemoryStore());
        $completer = $this->placingCompleter(
            $this->orderGatewayReading($this->placedOrder(OrderTransactionStates::STATE_FAILED), $context),
            $this->recordingSessionManager($calls, ['status' => CheckoutStatus::Incomplete->value]),
            $completionStore,
            $paymentGateway,
            $context,
            $lockFactory,
        );

        $checkout = $completer->retryPayment(self::CHECKOUT_ID, self::ORDER_ID, $context, new RequestContext('shop.example'), $instrument);

        static::assertSame(CheckoutStatus::Completed, $checkout->status);
        static::assertSame(self::ORDER_ID, $checkout->order?->id);
        static::assertSame([['session', CheckoutStatus::Completed->value, []]], $calls);
        static::assertTrue($lockFactory->createLock(self::LOCK_KEY)->acquire(false), 'Lock must be released after the retry');
    }

    #[Test]
    public function testRetryPaymentDoesNotPayAgainWhenAnotherRequestPaidTheOrderInTheMeantime(): void
    {
        $context = $this->customerContext();
        $calls = [];

        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $completer = $this->placingCompleter(
            $this->orderGatewayReading($this->placedOrder(OrderTransactionStates::STATE_PAID), $context),
            $this->recordingSessionManager($calls, ['status' => CheckoutStatus::Completed->value]),
            $this->recordingCompletionStore($calls),
            $paymentGateway,
            $context,
        );

        $checkout = $completer->retryPayment(self::CHECKOUT_ID, self::ORDER_ID, $context, new RequestContext('shop.example'), new PaymentInstrument('card', 'com.example.psp'));

        static::assertSame(CheckoutStatus::Completed, $checkout->status);
        static::assertSame(self::ORDER_ID, $checkout->order?->id);
        static::assertSame([], $calls, 'Nothing is saved when the payment is not retried');
    }

    #[Test]
    public function testRetryPaymentReplaysAFailureThatCannotBePaidAgain(): void
    {
        $context = $this->customerContext();
        $calls = [];

        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $completer = $this->placingCompleter(
            $this->orderGatewayReading($this->placedOrder(OrderTransactionStates::STATE_FAILED), $context),
            $this->recordingSessionManager($calls, ['status' => CheckoutStatus::Incomplete->value, CompletionPaymentResult::SESSION_RETRYABLE_KEY => false]),
            $this->recordingCompletionStore($calls),
            $paymentGateway,
            $context,
        );

        $checkout = $completer->retryPayment(self::CHECKOUT_ID, self::ORDER_ID, $context, new RequestContext('shop.example'), new PaymentInstrument('card', 'com.example.psp'));

        static::assertSame(CheckoutStatus::Incomplete, $checkout->status);
        static::assertSame([['payment_failed', 'unrecoverable']], $this->messageCodes($checkout));
        static::assertSame([], $calls);
    }

    #[Test]
    public function testAnApplierRefusalKeepsTheStatusWithOneRecoverablePaymentFailedAndPlacesNoOrder(): void
    {
        $calls = [];
        $refusingApplier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $refusingApplier->method('apply')->willThrowException(CompletionPaymentException::paymentMethodUnavailable('com.example.psp'));
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('pay');
        $customerContext = $this->customerContext();
        $this->withLegalLinkDomain($customerContext);

        $completer = $this->placingCompleter($orderGateway, $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext, null, $this->completionPayment($paymentGateway, $refusingApplier));

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::ReadyForComplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
        static::assertSame(self::CHECKOUT_ID, $checkout->id);
        static::assertNull($checkout->order);
        static::assertSame([[
            'type' => 'error',
            'content' => 'The payment handler "com.example.psp" has no payment method that is available to this checkout.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0]',
        ]], array_map(static fn (Message $message): array => $message->toArray(), $checkout->messages));
        static::assertSame([['session', CheckoutStatus::ReadyForComplete->value, null]], $calls, 'The session follows the rotated context token, and no completion row is written');
    }

    #[Test]
    public function testTheValidationExceptionOfAReplacementApplierIsRefusedLikeTheInternalOne(): void
    {
        $calls = [];
        $refusingApplier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $refusingApplier->method('apply')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $customerContext = $this->customerContext();
        $this->withLegalLinkDomain($customerContext);

        $completer = $this->placingCompleter($orderGateway, $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext, null, $this->completionPayment($paymentGateway, $refusingApplier));

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::ReadyForComplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
        static::assertSame([['payment_failed', 'recoverable']], $this->messageCodes($checkout));
        static::assertSame('The instrument is not accepted for this buyer.', $checkout->messages[0]->content);
        static::assertSame([['session', CheckoutStatus::ReadyForComplete->value, null]], $calls, 'The session follows the rotated context token');
    }

    #[Test]
    public function testAPaymentMethodThatChangesTheTotalIsRefusedWithoutPlacingAnOrder(): void
    {
        $calls = [];
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('pay');
        $customerContext = $this->customerContext();
        $this->withLegalLinkDomain($customerContext);
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willReturnArgument(1);

        $completer = $this->placingCompleter($orderGateway, $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext, null, $this->completionPayment($paymentGateway, $applier, $this->cartLoadRouteWithTotals(10.0, 11.5)));

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::ReadyForComplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertInstanceOf(Checkout::class, $checkout);
        static::assertSame(CheckoutStatus::ReadyForComplete, $checkout->status);
        static::assertSame([['payment_failed', 'recoverable']], $this->messageCodes($checkout));
        static::assertStringContainsString('Update the checkout with this instrument first', $checkout->messages[0]->content);
        static::assertSame('$.payment.instruments[0]', $checkout->messages[0]->path);
        static::assertSame([['session', CheckoutStatus::ReadyForComplete->value, null]], $calls);
    }

    #[Test]
    public function testAPaymentMethodThatKeepsTheTotalLetsTheCompletionGoOn(): void
    {
        $calls = [];
        $customerContext = $this->customerContext();
        $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $applier->method('apply')->willReturnArgument(1);
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->method('pay')->willReturn(null);

        $completer = $this->placingCompleter($this->orderGatewayPlacing($this->placedOrder()), $this->recordingSessionManager($calls), $this->recordingCompletionStore($calls), $paymentGateway, $customerContext, null, $this->completionPayment($paymentGateway, $applier, $this->cartLoadRouteWithTotals(10.0, 10.0)));

        $checkout = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, new PaymentInstrument('card', 'com.example.psp'));

        static::assertSame(CheckoutStatus::Completed, $checkout?->status);
    }

    #[Test]
    public function testTheErrorUrlFallsBackToTheOrderPermalinkWithoutAContinueUrl(): void
    {
        $instrument = new PaymentInstrument('card', 'com.example.psp');
        $customerContext = $this->customerContext();
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::once())
            ->method('pay')
            ->with(self::ORDER_ID, $customerContext, $instrument, self::PERMALINK_URL, self::PERMALINK_URL)
            ->willReturn(null);

        $completer = $this->placingCompleter($this->orderGatewayPlacing($this->placedOrder()), $this->nullSessionManager(), $this->openCompletionStore(), $paymentGateway, $customerContext, null, null, null);

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, $instrument);
    }

    #[Test]
    public function testRetryPaymentWhoseInstrumentTheApplierRefusesKeepsThePaymentStateAndAddsTheRefusal(): void
    {
        $customerContext = $this->customerContext();
        $calls = [];
        $refusingApplier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $refusingApplier->method('apply')->willThrowException(CompletionPaymentException::handlerNotRegistered('com.example.psp'));
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $completer = $this->placingCompleter(
            $this->orderGatewayReading($this->placedOrder(OrderTransactionStates::STATE_FAILED), $customerContext),
            $this->recordingSessionManager($calls, ['status' => CheckoutStatus::Incomplete->value]),
            $this->recordingCompletionStore($calls),
            $paymentGateway,
            $customerContext,
            null,
            $this->completionPayment($paymentGateway, $refusingApplier),
        );

        $checkout = $completer->retryPayment(self::CHECKOUT_ID, self::ORDER_ID, $customerContext, new RequestContext('shop.example'), new PaymentInstrument('card', 'com.example.psp'));

        static::assertSame(CheckoutStatus::Incomplete, $checkout->status);
        static::assertSame([['payment_failed', 'recoverable']], $this->messageCodes($checkout));
        static::assertSame('No payment handler "com.example.psp" is registered.', $checkout->messages[0]->content);
        static::assertSame([], $calls);
    }

    #[Test]
    public function testRetryPaymentWhoseInstrumentAReplacementApplierRefusesWithAValidationExceptionAddsTheRefusal(): void
    {
        $customerContext = $this->customerContext();
        $calls = [];
        $refusingApplier = $this->createMock(AbstractCompletionPaymentApplier::class);
        $refusingApplier->method('apply')->willThrowException(new ValidationException('The instrument is not accepted for this buyer.'));
        $paymentGateway = $this->createMock(OrderPaymentGatewayInterface::class);
        $paymentGateway->expects(static::never())->method('changePaymentMethod');
        $paymentGateway->expects(static::never())->method('pay');

        $completer = $this->placingCompleter(
            $this->orderGatewayReading($this->placedOrder(OrderTransactionStates::STATE_FAILED), $customerContext),
            $this->recordingSessionManager($calls, ['status' => CheckoutStatus::Incomplete->value]),
            $this->recordingCompletionStore($calls),
            $paymentGateway,
            $customerContext,
            null,
            $this->completionPayment($paymentGateway, $refusingApplier),
        );

        $checkout = $completer->retryPayment(self::CHECKOUT_ID, self::ORDER_ID, $customerContext, new RequestContext('shop.example'), new PaymentInstrument('card', 'com.example.psp'));

        static::assertSame([['payment_failed', 'recoverable']], $this->messageCodes($checkout));
        static::assertSame('The instrument is not accepted for this buyer.', $checkout->messages[0]->content);
        static::assertSame([], $calls);
    }

    #[Test]
    public function testThePresentedInstrumentReachesTheOrderPlacement(): void
    {
        $instrument = new PaymentInstrument('card', 'com.example.psp', ['token' => 'tok_1']);
        $customerContext = $this->customerContext();

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())
            ->method('placeOrder')
            ->with(static::isInstanceOf(Cart::class), $customerContext, $instrument)
            ->willReturn($this->placedOrder());

        $completer = $this->placingCompleter($orderGateway, $this->nullSessionManager(), $this->openCompletionStore(), $this->createMock(OrderPaymentGatewayInterface::class), $customerContext);

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $this->salesChannelContext(), new RequestContext('shop.example'), CheckoutStatus::Incomplete, $instrument);
    }

    private function placingCompleter(
        OrderGatewayInterface $orderGateway,
        CheckoutSessionManagerInterface $sessionManager,
        CheckoutCompletionStoreInterface $completionStore,
        OrderPaymentGatewayInterface $paymentGateway,
        SalesChannelContext $customerContext,
        ?LockFactory $lockFactory = null,
        ?CompletionPaymentProcessor $completionPayment = null,
        ?string $continueUrl = self::CONTINUE_URL,
    ): CheckoutCompleter {
        $provisioner = $this->createMock(GuestCustomerContextProvisionerInterface::class);
        $provisioner->method('ensureGuestCustomer')->willReturn($customerContext);

        $continueUrlBuilder = $this->createMock(CheckoutContinueUrlBuilderInterface::class);
        $continueUrlBuilder->method('build')->willReturn($continueUrl);

        return new CheckoutCompleter(
            $orderGateway,
            new ShopwareDataMapper(),
            $provisioner,
            $this->nullConfigService(),
            $sessionManager,
            $completionStore,
            $lockFactory ?? new LockFactory(new InMemoryStore()),
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
            new OrderPermalinkBuilder(),
            $completionPayment ?? $this->completionPayment($paymentGateway),
            $this->legalLinkBuilder(),
        );
    }

    private function completionPayment(?OrderPaymentGatewayInterface $paymentGateway = null, ?AbstractCompletionPaymentApplier $applier = null, ?AbstractCartLoadRoute $cartLoadRoute = null): CompletionPaymentProcessor
    {
        if (null === $applier) {
            $applier = $this->createMock(AbstractCompletionPaymentApplier::class);
            $applier->method('apply')->willReturnArgument(1);
        }

        return new CompletionPaymentProcessor(
            $applier,
            $paymentGateway ?? $this->createMock(OrderPaymentGatewayInterface::class),
            $cartLoadRoute ?? new RecordingCartLoadRoute(new Cart(self::CHECKOUT_ID)),
        );
    }

    private function cartLoadRouteWithTotals(float ...$totals): AbstractCartLoadRoute
    {
        $responses = array_map(static function (float $total): CartResponse {
            $cart = new Cart(self::CHECKOUT_ID);
            $cart->setPrice(new CartPrice($total, $total, $total, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

            return new CartResponse($cart);
        }, $totals);

        $cartLoadRoute = $this->createMock(AbstractCartLoadRoute::class);
        $cartLoadRoute->method('load')->willReturnOnConsecutiveCalls(...$responses);

        return $cartLoadRoute;
    }

    private function orderGatewayPlacing(OrderEntity $order): OrderGatewayInterface
    {
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('placeOrder')->willReturn($order);

        return $orderGateway;
    }

    private function orderGatewayReading(OrderEntity $order, SalesChannelContext $context): OrderGatewayInterface
    {
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $orderGateway->method('getOrderForSalesChannelContext')->with(self::ORDER_ID, $context)->willReturn($order);

        return $orderGateway;
    }

    private function openCompletionStore(): CheckoutCompletionStoreInterface&MockObject
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        return $completionStore;
    }

    /**
     * @param list<list<mixed>> $calls
     */
    private function recordingCompletionStore(array &$calls): CheckoutCompletionStoreInterface&MockObject
    {
        $completionStore = $this->openCompletionStore();
        $completionStore->method('complete')->willReturnCallback(static function (string $checkoutId, string $orderId) use (&$calls): void {
            $calls[] = ['completion row', $orderId];
        });

        return $completionStore;
    }

    /**
     * Records every save as `['session', status, what the payment adds to the session]`.
     *
     * @param list<list<mixed>>    $calls
     * @param array<string, mixed> $storedSession what `load()` answers
     */
    private function recordingSessionManager(array &$calls, array $storedSession = []): CheckoutSessionManagerInterface
    {
        $sessionManager = $this->createMock(CheckoutSessionManagerInterface::class);
        $sessionManager->method('load')->with(self::CHECKOUT_ID, self::SALES_CHANNEL_ID)->willReturn($storedSession);
        $sessionManager->method('saveForCheckoutId')->willReturnCallback(
            static function (string $checkoutId, SalesChannelContext $context, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null, ?CompletionPaymentResult $payment = null) use (&$calls): void {
                $calls[] = ['session', $status, $payment?->sessionData()];
            },
        );

        return $sessionManager;
    }

    /**
     * @return list<array{0: string|null, 1: string|null}>
     */
    private function messageCodes(Checkout $checkout): array
    {
        return array_map(static fn (Message $message): array => [$message->code, $message->severity], $checkout->messages);
    }

    private function customerContext(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(self::PAYMENT_METHOD_ID);

        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $customerContext->method('getCurrency')->willReturn($currency);
        $customerContext->method('getToken')->willReturn(self::ROTATED_CONTEXT_TOKEN);
        $customerContext->method('getPaymentMethod')->willReturn($paymentMethod);

        return $customerContext;
    }

    private function placedOrder(?string $transactionState = null): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setDeepLinkCode(self::DEEP_LINK_CODE);
        $order->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, $taxes, $taxRules));

        if (null !== $transactionState) {
            $state = new StateMachineStateEntity();
            $state->setId('00000000000000000000000000000007');
            $state->setTechnicalName($transactionState);

            $transaction = new OrderTransactionEntity();
            $transaction->setId('00000000000000000000000000000008');
            $transaction->setCreatedAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
            $transaction->setStateMachineState($state);
            $order->setTransactions(new OrderTransactionCollection([$transaction]));
        }

        return $order;
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
     * Only a context with a storefront domain gets legal links, which proves which context the completer used.
     */
    private function withLegalLinkDomain(SalesChannelContext&MockObject $context): void
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId(self::DOMAIN_ID);
        $domain->setUrl('https://shop.example');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setTypeId(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
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
            public function load(string $checkoutId, string $salesChannelId): array
            {
                return [];
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

            public function saveForCheckoutId(string $checkoutId, SalesChannelContext $salesChannelContext, string $status, ?Buyer $buyer, array $discountCodes = [], ?string $orderId = null, ?string $orderDeepLinkCode = null, ?array $guestAddress = null, ?array $guestShippingAddress = null, ?CompletionPaymentResult $payment = null): void
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
