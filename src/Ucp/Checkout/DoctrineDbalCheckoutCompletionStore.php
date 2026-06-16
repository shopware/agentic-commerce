<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

final class DoctrineDbalCheckoutCompletionStore implements CheckoutCompletionStoreInterface
{
    private const TABLE = 'swag_agentic_commerce_ucp_checkout_completion';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function complete(string $checkoutId, string $salesChannelId, string $orderId): void
    {
        $this->connection->insert(self::TABLE, [
            'checkout_id' => $checkoutId,
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'order_id' => Uuid::fromHexToBytes($orderId),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    public function completedOrderId(string $checkoutId, string $salesChannelId): ?string
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(order_id)) AS order_id FROM `%s` WHERE checkout_id = :checkoutId AND sales_channel_id = :salesChannelId',
                self::TABLE,
            ),
            [
                'checkoutId' => $checkoutId,
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ],
        );

        if (false === $row) {
            return null;
        }

        $orderId = $row['order_id'] ?? null;

        return \is_string($orderId) && '' !== $orderId ? $orderId : null;
    }
}
