<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservation;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservationStatus;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/**
 * @internal
 */
#[CoversClass(CheckoutCompleter::class)]
#[CoversClass(CheckoutCompletionReservation::class)]
#[CoversClass(CheckoutCompletionReservationStatus::class)]
final class CheckoutCompleterTest extends TestCase
{
    private const CHECKOUT_ID = 'checkout-token';
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const ORDER_ID = '00000000000000000000000000000002';

    #[Test]
    public function testProcessingReservationSkipsOrderPlacement(): void
    {
        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::never())->method('placeOrder');
        $orderGateway->expects(static::never())->method('getOrder');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $completionStore = new class implements CheckoutCompletionStoreInterface {
            public function reserve(string $checkoutId, string $salesChannelId): CheckoutCompletionReservation
            {
                return CheckoutCompletionReservation::processing();
            }

            public function complete(string $checkoutId, string $salesChannelId, string $orderId): void
            {
                throw new \BadMethodCallException('Completion should not be marked for a processing reservation.');
            }

            public function release(string $checkoutId, string $salesChannelId): void
            {
                throw new \BadMethodCallException('Processing reservations owned by another request must not be released.');
            }

            public function completedOrderId(string $checkoutId, string $salesChannelId): ?string
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }
        };

        $completer = new CheckoutCompleter(
            $orderGateway,
            $this->uninitialized(ShopwareDataMapper::class),
            $this->uninitialized(GuestCustomerContextProvisioner::class),
            $this->uninitialized(UcpConfigService::class),
            $this->uninitialized(CheckoutSessionManager::class),
            $completionStore,
            $this->uninitialized(CheckoutContinueUrlBuilder::class),
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
        );

        $this->expectExceptionObject(new ValidationException('Checkout completion is already processing; retry the same checkout id after the in-flight request finishes.'));

        $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));
    }

    #[Test]
    public function testCompletedReservationReplaysExistingOrder(): void
    {
        $completionStore = new class(self::ORDER_ID) implements CheckoutCompletionStoreInterface {
            public function __construct(private readonly string $orderId) {}

            public function reserve(string $checkoutId, string $salesChannelId): CheckoutCompletionReservation
            {
                return CheckoutCompletionReservation::completed($this->orderId);
            }

            public function complete(string $checkoutId, string $salesChannelId, string $orderId): void
            {
                throw new \BadMethodCallException('complete() must not be called for an already-completed reservation.');
            }

            public function release(string $checkoutId, string $salesChannelId): void
            {
                throw new \BadMethodCallException('release() must not be called for an already-completed reservation.');
            }

            public function completedOrderId(string $checkoutId, string $salesChannelId): ?string
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }
        };

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn($currency);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())->method('getOrder')->with(self::ORDER_ID)->willReturn($order);
        $orderGateway->expects(static::never())->method('placeOrder');

        $expectedCheckout = $this->createMock(Checkout::class);

        $mapper = $this->createMock(ShopwareDataMapper::class);
        $mapper->expects(static::once())->method('toCompletedCheckout')->willReturn($expectedCheckout);

        $continueUrlBuilder = $this->createMock(CheckoutContinueUrlBuilder::class);
        $continueUrlBuilder->method('build')->willReturn('https://example.com/continue');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $mapper,
            $this->uninitialized(GuestCustomerContextProvisioner::class),
            $this->uninitialized(UcpConfigService::class),
            $this->uninitialized(CheckoutSessionManager::class),
            $completionStore,
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
        );

        $result = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));

        static::assertSame($expectedCheckout, $result);
    }

    #[Test]
    public function testAcquiredReservationPlacesOrderAndCompletesStore(): void
    {
        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('reserve')->willReturn(CheckoutCompletionReservation::acquired());
        $completionStore->expects(static::once())->method('complete')->with(self::CHECKOUT_ID, self::SALES_CHANNEL_ID, self::ORDER_ID);
        $completionStore->expects(static::never())->method('release');

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');

        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $customerContext->method('getCurrency')->willReturn($currency);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn(self::ORDER_ID);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->expects(static::once())->method('placeOrder')->willReturn($order);
        $orderGateway->expects(static::never())->method('getOrder');

        $sessionManager = $this->createMock(CheckoutSessionManager::class);
        $sessionManager->method('buyer')->willReturn(null);
        $sessionManager->method('guestAddress')->willReturn(null);
        $sessionManager->expects(static::once())->method('save');

        $guestCustomerContextProvisioner = $this->createMock(GuestCustomerContextProvisioner::class);
        $guestCustomerContextProvisioner->method('ensureGuestCustomer')->willReturn($customerContext);

        $configService = $this->createMock(UcpConfigService::class);
        $configService->method('getConfig')->willReturn(new UcpConfig());

        $expectedCheckout = $this->createMock(Checkout::class);

        $mapper = $this->createMock(ShopwareDataMapper::class);
        $mapper->expects(static::once())->method('toCompletedCheckout')->willReturn($expectedCheckout);

        $continueUrlBuilder = $this->createMock(CheckoutContinueUrlBuilder::class);
        $continueUrlBuilder->method('build')->willReturn('https://example.com/continue');

        $orderWebhookPublisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $orderWebhookPublisher->expects(static::never())->method('publish');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $mapper,
            $guestCustomerContextProvisioner,
            $configService,
            $sessionManager,
            $completionStore,
            $continueUrlBuilder,
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $orderWebhookPublisher,
        );

        $result = $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));

        static::assertSame($expectedCheckout, $result);
    }

    #[Test]
    public function testOrderPlacementExceptionReleasesReservation(): void
    {
        $tracker = new \stdClass();
        $tracker->releaseCalled = 0;

        $completionStore = new class($tracker) implements CheckoutCompletionStoreInterface {
            public function __construct(private readonly \stdClass $tracker) {}

            public function reserve(string $checkoutId, string $salesChannelId): CheckoutCompletionReservation
            {
                return CheckoutCompletionReservation::acquired();
            }

            public function complete(string $checkoutId, string $salesChannelId, string $orderId): void
            {
                throw new \BadMethodCallException('complete() must not be called when order placement fails.');
            }

            public function release(string $checkoutId, string $salesChannelId): void
            {
                ++$this->tracker->releaseCalled;
            }

            public function completedOrderId(string $checkoutId, string $salesChannelId): ?string
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }
        };

        $customerContext = $this->createMock(SalesChannelContext::class);
        $customerContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $orderGateway = $this->createMock(OrderGatewayInterface::class);
        $orderGateway->method('placeOrder')->willThrowException(new \RuntimeException('Order placement failed.'));
        $orderGateway->expects(static::never())->method('getOrder');

        $sessionManager = $this->createMock(CheckoutSessionManager::class);
        $sessionManager->method('buyer')->willReturn(null);
        $sessionManager->method('guestAddress')->willReturn(null);

        $guestCustomerContextProvisioner = $this->createMock(GuestCustomerContextProvisioner::class);
        $guestCustomerContextProvisioner->method('ensureGuestCustomer')->willReturn($customerContext);

        $configService = $this->createMock(UcpConfigService::class);
        $configService->method('getConfig')->willReturn(new UcpConfig());

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $completer = new CheckoutCompleter(
            $orderGateway,
            $this->uninitialized(ShopwareDataMapper::class),
            $guestCustomerContextProvisioner,
            $configService,
            $sessionManager,
            $completionStore,
            $this->uninitialized(CheckoutContinueUrlBuilder::class),
            $this->uninitialized(CheckoutWebhookUrlGuard::class),
            $this->createMock(OrderWebhookPublisherInterface::class),
        );

        try {
            $completer->complete(self::CHECKOUT_ID, [], new Cart(self::CHECKOUT_ID), $salesChannelContext, new RequestContext('shop.example'));
            static::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            static::assertSame('Order placement failed.', $e->getMessage());
        }

        static::assertSame(1, $tracker->releaseCalled, 'release() must be called exactly once to free the reservation on failure.');
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
