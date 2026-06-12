<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservation;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionReservationStatus;
use Swag\AgenticCommerce\Ucp\Checkout\DoctrineDbalCheckoutCompletionStore;

/**
 * @internal
 */
#[CoversClass(DoctrineDbalCheckoutCompletionStore::class)]
#[CoversClass(CheckoutCompletionReservation::class)]
#[CoversClass(CheckoutCompletionReservationStatus::class)]
final class DoctrineDbalCheckoutCompletionStoreTest extends TestCase
{
    private const TABLE = 'swag_agentic_commerce_ucp_checkout_completion';
    private const CHECKOUT_ID = 'checkout-token';
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const ORDER_ID = '00000000000000000000000000000002';

    #[Test]
    public function testReserveAcquiresNewCompletion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('insert')
            ->with(
                self::TABLE,
                static::callback(static function (array $payload): bool {
                    static::assertSame(self::CHECKOUT_ID, $payload['checkout_id']);
                    static::assertSame(Uuid::fromHexToBytes(self::SALES_CHANNEL_ID), $payload['sales_channel_id']);
                    static::assertSame('processing', $payload['status']);
                    static::assertNull($payload['order_id']);
                    static::assertIsString($payload['created_at']);
                    static::assertNull($payload['updated_at']);

                    return true;
                }),
            )
            ->willReturn(1);
        $connection->expects(static::never())->method('fetchAssociative');

        $reservation = (new DoctrineDbalCheckoutCompletionStore($connection))->reserve(self::CHECKOUT_ID, self::SALES_CHANNEL_ID);

        static::assertSame(CheckoutCompletionReservationStatus::Acquired, $reservation->status);
        static::assertNull($reservation->orderId);
    }

    #[Test]
    public function testReserveReturnsCompletedReservationAfterUniqueCollision(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException($this->uniqueConstraintViolation());
        $connection->expects(static::once())
            ->method('fetchAssociative')
            ->with(
                static::stringContains(self::TABLE),
                [
                    'checkoutId' => self::CHECKOUT_ID,
                    'salesChannelId' => Uuid::fromHexToBytes(self::SALES_CHANNEL_ID),
                ],
            )
            ->willReturn([
                'status' => 'completed',
                'order_id' => self::ORDER_ID,
            ]);

        $reservation = (new DoctrineDbalCheckoutCompletionStore($connection))->reserve(self::CHECKOUT_ID, self::SALES_CHANNEL_ID);

        static::assertSame(CheckoutCompletionReservationStatus::Completed, $reservation->status);
        static::assertSame(self::ORDER_ID, $reservation->orderId);
    }

    #[Test]
    public function testReserveReturnsProcessingReservationAfterUniqueCollisionWithoutCompletedOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException($this->uniqueConstraintViolation());
        $connection->method('fetchAssociative')->willReturn([
            'status' => 'processing',
            'order_id' => null,
        ]);

        $reservation = (new DoctrineDbalCheckoutCompletionStore($connection))->reserve(self::CHECKOUT_ID, self::SALES_CHANNEL_ID);

        static::assertSame(CheckoutCompletionReservationStatus::Processing, $reservation->status);
        static::assertNull($reservation->orderId);
    }

    #[Test]
    public function testCompletedOrderIdReturnsNullWhenRecordIsNotCompleted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'status' => 'processing',
            'order_id' => null,
        ]);

        static::assertNull((new DoctrineDbalCheckoutCompletionStore($connection))->completedOrderId(self::CHECKOUT_ID, self::SALES_CHANNEL_ID));
    }

    #[Test]
    public function testCompleteMarksProcessingRecordCompleted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('executeStatement')
            ->with(
                static::logicalAnd(
                    static::stringContains('UPDATE `'.self::TABLE.'`'),
                    static::stringContains('status = :processingStatus'),
                    static::stringContains('order_id IS NULL'),
                ),
                static::callback(static function (array $params): bool {
                    static::assertSame('completed', $params['completedStatus']);
                    static::assertSame(Uuid::fromHexToBytes(self::ORDER_ID), $params['orderId']);
                    static::assertSame(self::CHECKOUT_ID, $params['checkoutId']);
                    static::assertSame(Uuid::fromHexToBytes(self::SALES_CHANNEL_ID), $params['salesChannelId']);
                    static::assertSame('processing', $params['processingStatus']);

                    return true;
                }),
            )
            ->willReturn(1);

        (new DoctrineDbalCheckoutCompletionStore($connection))->complete(self::CHECKOUT_ID, self::SALES_CHANNEL_ID, self::ORDER_ID);
    }

    #[Test]
    public function testCompleteFailsWhenProcessingRecordWasNotUpdated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $this->expectExceptionObject(new \RuntimeException('Unable to mark checkout "checkout-token" completion as completed.'));

        (new DoctrineDbalCheckoutCompletionStore($connection))->complete(self::CHECKOUT_ID, self::SALES_CHANNEL_ID, self::ORDER_ID);
    }

    #[Test]
    public function testReleaseDeletesOnlyProcessingReservation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('delete')
            ->with(self::TABLE, [
                'checkout_id' => self::CHECKOUT_ID,
                'sales_channel_id' => Uuid::fromHexToBytes(self::SALES_CHANNEL_ID),
                'status' => 'processing',
            ])
            ->willReturn(1);

        (new DoctrineDbalCheckoutCompletionStore($connection))->release(self::CHECKOUT_ID, self::SALES_CHANNEL_ID);
    }

    private function uniqueConstraintViolation(): UniqueConstraintViolationException
    {
        /** @var UniqueConstraintViolationException $exception */
        $exception = (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();

        return $exception;
    }
}
