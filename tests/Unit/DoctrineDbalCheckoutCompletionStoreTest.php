<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Checkout\DoctrineDbalCheckoutCompletionStore;

/**
 * @internal
 */
#[CoversClass(DoctrineDbalCheckoutCompletionStore::class)]
final class DoctrineDbalCheckoutCompletionStoreTest extends TestCase
{
    private const TABLE = 'swag_agentic_commerce_ucp_checkout_completion';
    private const CHECKOUT_ID = 'checkout-token';
    private const ORDER_ID = '00000000000000000000000000000002';

    #[Test]
    public function testCompleteInsertsRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('insert')
            ->with(
                self::TABLE,
                static::callback(static function (array $payload): bool {
                    static::assertSame(self::CHECKOUT_ID, $payload['checkout_id']);
                    static::assertSame(Uuid::fromHexToBytes(self::ORDER_ID), $payload['order_id']);
                    static::assertArrayNotHasKey('sales_channel_id', $payload);
                    static::assertIsString($payload['created_at']);

                    return true;
                }),
            );

        (new DoctrineDbalCheckoutCompletionStore($connection))->complete(self::CHECKOUT_ID, self::ORDER_ID);
    }

    #[Test]
    public function testCompletedOrderIdReturnsNullWhenNoRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        static::assertNull((new DoctrineDbalCheckoutCompletionStore($connection))->completedOrderId(self::CHECKOUT_ID));
    }

    #[Test]
    public function testCompletedOrderIdReturnsStoredOrderId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchAssociative')
            ->with(
                static::stringContains(self::TABLE),
                ['checkoutId' => self::CHECKOUT_ID],
            )
            ->willReturn(['order_id' => self::ORDER_ID]);

        static::assertSame(self::ORDER_ID, (new DoctrineDbalCheckoutCompletionStore($connection))->completedOrderId(self::CHECKOUT_ID));
    }

    #[Test]
    public function testCompletedCheckoutIdReturnsStoredCheckoutId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('fetchAssociative')
            ->with(
                static::stringContains(self::TABLE),
                ['orderId' => Uuid::fromHexToBytes(self::ORDER_ID)],
            )
            ->willReturn(['checkout_id' => self::CHECKOUT_ID]);

        static::assertSame(self::CHECKOUT_ID, (new DoctrineDbalCheckoutCompletionStore($connection))->completedCheckoutId(self::ORDER_ID));
    }
}
