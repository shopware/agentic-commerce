<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservation;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservationStatus;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Swag\AgenticCommerce\Ucp\Gateway\OrderGatewayInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Ucp\Sdk\Exception\ValidationException;
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
