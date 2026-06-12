<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final class DoctrineDbalCheckoutCompletionStore implements CheckoutCompletionStoreInterface
{
    private const TABLE = 'swag_agentic_commerce_ucp_checkout_completion';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function reserve(string $checkoutId, string $salesChannelId): CheckoutCompletionReservation
    {
        $timestamp = $this->now();

        try {
            $this->connection->insert(self::TABLE, [
                'checkout_id' => $checkoutId,
                'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
                'status' => self::STATUS_PROCESSING,
                'order_id' => null,
                'created_at' => $timestamp,
                'updated_at' => null,
            ]);

            return CheckoutCompletionReservation::acquired();
        } catch (UniqueConstraintViolationException) {
            $orderId = $this->completedOrderId($checkoutId, $salesChannelId);
            if (null !== $orderId) {
                return CheckoutCompletionReservation::completed($orderId);
            }

            return CheckoutCompletionReservation::processing();
        }
    }

    public function complete(string $checkoutId, string $salesChannelId, string $orderId): void
    {
        $updated = $this->connection->executeStatement(
            \sprintf(
                'UPDATE `%s` SET status = :completedStatus, order_id = :orderId, updated_at = :updatedAt WHERE checkout_id = :checkoutId AND sales_channel_id = :salesChannelId AND status = :processingStatus AND order_id IS NULL',
                self::TABLE,
            ),
            [
                'completedStatus' => self::STATUS_COMPLETED,
                'orderId' => Uuid::fromHexToBytes($orderId),
                'updatedAt' => $this->now(),
                'checkoutId' => $checkoutId,
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                'processingStatus' => self::STATUS_PROCESSING,
            ],
        );

        if (1 !== $updated) {
            throw new \RuntimeException(\sprintf('Unable to mark checkout "%s" completion as completed.', $checkoutId));
        }
    }

    public function release(string $checkoutId, string $salesChannelId): void
    {
        $this->connection->delete(self::TABLE, [
            'checkout_id' => $checkoutId,
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'status' => self::STATUS_PROCESSING,
        ]);
    }

    public function completedOrderId(string $checkoutId, string $salesChannelId): ?string
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT status, LOWER(HEX(order_id)) AS order_id FROM `%s` WHERE checkout_id = :checkoutId AND sales_channel_id = :salesChannelId',
                self::TABLE,
            ),
            [
                'checkoutId' => $checkoutId,
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ],
        );

        if (false === $row || ($row['status'] ?? null) !== self::STATUS_COMPLETED) {
            return null;
        }

        $orderId = $row['order_id'] ?? null;

        return \is_string($orderId) && '' !== $orderId ? $orderId : null;
    }

    private function now(): string
    {
        return (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
