<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/** @internal */
#[Package('framework')]
final class DoctrineDbalUcpConfigRepository implements UcpConfigRepositoryInterface
{
    private const TABLE = 'swag_agentic_commerce_ucp_config';

    public function __construct(
        private readonly Connection $connection,
        private readonly bool $allowHttpLocalWebhookOverride = false,
    ) {
    }

    public function find(string $salesChannelId): ?UcpConfig
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT config_json FROM `%s` WHERE sales_channel_id = :salesChannelId', self::TABLE),
            ['salesChannelId' => Uuid::fromHexToBytes($salesChannelId)],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findMany(array $salesChannelIds): array
    {
        if ([] === $salesChannelIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, config_json FROM `%s` WHERE LOWER(HEX(sales_channel_id)) IN (:salesChannelIds)',
                self::TABLE,
            ),
            ['salesChannelIds' => array_map('strtolower', $salesChannelIds)],
            ['salesChannelIds' => $this->getStringArrayParameterType()],
        );

        $configs = [];
        foreach ($rows as $row) {
            $salesChannelId = (string) ($row['sales_channel_id'] ?? '');
            if ('' === $salesChannelId) {
                continue;
            }

            $configs[$salesChannelId] = $this->hydrate($row);
        }

        return $configs;
    }

    public function save(string $salesChannelId, UcpConfig $config): void
    {
        $timestamp = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $criteria = ['sales_channel_id' => Uuid::fromHexToBytes($salesChannelId)];
        $payload = [
            'config_json' => $this->encode($config),
            'updated_at' => $timestamp,
        ];

        $updated = $this->connection->update(self::TABLE, $payload, $criteria);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert(self::TABLE, [
                ...$criteria,
                ...$payload,
                'created_at' => $timestamp,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update(self::TABLE, $payload, $criteria);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UcpConfig
    {
        return UcpConfig::fromJson((string) ($row['config_json'] ?? '{}'), $this->allowHttpLocalWebhookOverride);
    }

    private function encode(UcpConfig $config): string
    {
        return json_encode($config->toArray(), \JSON_THROW_ON_ERROR);
    }

    private function getStringArrayParameterType(): mixed
    {
        if (class_exists(ArrayParameterType::class)) {
            return ArrayParameterType::STRING;
        }

        $legacyType = \constant(Connection::class.'::PARAM_STR_ARRAY');

        return $legacyType;
    }
}
