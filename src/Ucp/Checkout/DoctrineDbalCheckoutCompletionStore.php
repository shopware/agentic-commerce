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

    public function complete(string $checkoutId, string $orderId): void
    {
        $this->connection->insert(self::TABLE, [
            'checkout_id' => $checkoutId,
            'order_id' => Uuid::fromHexToBytes($orderId),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    public function completedOrderId(string $checkoutId): ?string
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(order_id)) AS order_id FROM `%s` WHERE checkout_id = :checkoutId',
                self::TABLE,
            ),
            ['checkoutId' => $checkoutId],
        );

        if (false === $row) {
            return null;
        }

        $orderId = $row['order_id'] ?? null;

        return \is_string($orderId) && '' !== $orderId ? $orderId : null;
    }

    public function completedCheckoutId(string $orderId): ?string
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT checkout_id FROM `%s` WHERE order_id = :orderId',
                self::TABLE,
            ),
            ['orderId' => Uuid::fromHexToBytes($orderId)],
        );

        if (false === $row) {
            return null;
        }

        $checkoutId = $row['checkout_id'] ?? null;

        return \is_string($checkoutId) && '' !== $checkoutId ? $checkoutId : null;
    }
}
