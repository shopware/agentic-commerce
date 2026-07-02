<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/** @internal */
#[Package('discovery')]
final class CoreSalesChannelFileBridge implements AgenticFilesCoreBridgeInterface
{
    private const FILE_FAMILY = 'agentic';
    private const UCP_CONFIG_TABLE = 'swag_agentic_commerce_ucp_config';

    /**
     * @var list<string>
     */
    private const FILE_NAMES = [
        'llms.txt',
        'agents.md',
        '.well-known/ai-catalog.json',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CoreSalesChannelFileFeature $feature,
    ) {
    }

    public function enableForSalesChannel(string $salesChannelId): void
    {
        if (!$this->feature->isDatabaseReady($this->connection) || !Uuid::isValid($salesChannelId)) {
            return;
        }

        foreach (self::FILE_NAMES as $fileName) {
            $this->enableFile($salesChannelId, $fileName);
        }
    }

    public function syncActiveUcpSalesChannels(): void
    {
        if (!$this->feature->isDatabaseReady($this->connection) || !$this->ucpConfigTableExists()) {
            return;
        }

        foreach ($this->loadActiveUcpSalesChannelIds() as $salesChannelId) {
            $this->enableForSalesChannel($salesChannelId);
        }
    }

    public static function syncActiveUcpSalesChannelsWithConnection(Connection $connection): void
    {
        (new self($connection, new CoreSalesChannelFileFeature()))->syncActiveUcpSalesChannels();
    }

    private function enableFile(string $salesChannelId, string $fileName): void
    {
        $timestamp = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $criteria = [
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'file_family' => self::FILE_FAMILY,
            'file_name' => $fileName,
        ];

        $updated = $this->connection->update('sales_channel_file', [
            'enabled' => true,
            'updated_at' => $timestamp,
        ], $criteria);

        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('sales_channel_file', [
                'id' => Uuid::randomBytes(),
                ...$criteria,
                'enabled' => true,
                'template_overrides' => null,
                'created_at' => $timestamp,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('sales_channel_file', [
                'enabled' => true,
                'updated_at' => $timestamp,
            ], $criteria);
        }
    }

    private function ucpConfigTableExists(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => self::UCP_CONFIG_TABLE],
        );
    }

    /**
     * @return list<string>
     */
    private function loadActiveUcpSalesChannelIds(): array
    {
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, config_json FROM `%s`',
            self::UCP_CONFIG_TABLE,
        ));

        $salesChannelIds = [];
        foreach ($rows as $row) {
            $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
            if (!\is_array($config) || ($config['active'] ?? false) !== true) {
                continue;
            }

            $salesChannelId = (string) ($row['sales_channel_id'] ?? '');
            if (Uuid::isValid($salesChannelId)) {
                $salesChannelIds[] = $salesChannelId;
            }
        }

        return array_values(array_unique($salesChannelIds));
    }
}
