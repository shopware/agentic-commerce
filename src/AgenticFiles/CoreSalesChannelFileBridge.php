<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;

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
        private readonly ?AbstractSalesChannelTypeResolver $salesChannelTypeResolver = null,
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

    public function supportsCoreFiles(): bool
    {
        return $this->feature->isDatabaseReady($this->connection);
    }

    public function fileStates(array $salesChannelIds, array $fileNames): array
    {
        $salesChannelIds = array_values(array_filter($salesChannelIds, Uuid::isValid(...)));
        if ([] === $salesChannelIds || [] === $fileNames || !$this->supportsCoreFiles()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(sales_channel_id)) AS sales_channel_id, file_name, enabled
                FROM sales_channel_file
                WHERE file_family = :fileFamily
                  AND sales_channel_id IN (:salesChannelIds)
                  AND file_name IN (:fileNames)
                SQL,
            [
                'fileFamily' => self::FILE_FAMILY,
                'salesChannelIds' => Uuid::fromHexToBytesList($salesChannelIds),
                'fileNames' => $fileNames,
            ],
            [
                'salesChannelIds' => ArrayParameterType::BINARY,
                'fileNames' => ArrayParameterType::STRING,
            ],
        );

        // The column collation matches file names case-insensitively, as core does; answer in the caller's spelling.
        $requestedFileNames = array_combine(array_map(mb_strtolower(...), $fileNames), $fileNames);

        $states = [];
        foreach ($rows as $row) {
            $fileName = $requestedFileNames[mb_strtolower((string) $row['file_name'])] ?? null;
            if (null !== $fileName) {
                $states[(string) $row['sales_channel_id']][$fileName] = (bool) $row['enabled'];
            }
        }

        return $states;
    }

    public function insertMissingFiles(string $salesChannelId, array $fileNames): array
    {
        if (!Uuid::isValid($salesChannelId) || !$this->supportsCoreFiles()) {
            return [];
        }

        $timestamp = self::now();
        $inserted = [];
        foreach ($fileNames as $fileName) {
            try {
                $this->insertEnabledRow($this->rowKey($salesChannelId, $fileName), $timestamp);
                $inserted[] = $fileName;
            } catch (UniqueConstraintViolationException) {
                // The row exists, possibly switched off by the merchant: insert-only leaves it alone.
            }
        }

        return $inserted;
    }

    private function enableFile(string $salesChannelId, string $fileName): void
    {
        $timestamp = self::now();
        $criteria = $this->rowKey($salesChannelId, $fileName);

        $updated = $this->connection->update('sales_channel_file', [
            'enabled' => true,
            'updated_at' => $timestamp,
        ], $criteria);

        if ($updated > 0) {
            return;
        }

        try {
            $this->insertEnabledRow($criteria, $timestamp);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('sales_channel_file', [
                'enabled' => true,
                'updated_at' => $timestamp,
            ], $criteria);
        }
    }

    private static function now(): string
    {
        return (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    /**
     * @return array{sales_channel_id: string, file_family: string, file_name: string}
     */
    private function rowKey(string $salesChannelId, string $fileName): array
    {
        return [
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'file_family' => self::FILE_FAMILY,
            'file_name' => $fileName,
        ];
    }

    /**
     * @param array{sales_channel_id: string, file_family: string, file_name: string} $rowKey
     */
    private function insertEnabledRow(array $rowKey, string $timestamp): void
    {
        $this->connection->insert('sales_channel_file', [
            'id' => Uuid::randomBytes(),
            ...$rowKey,
            'enabled' => true,
            'template_overrides' => null,
            'created_at' => $timestamp,
        ]);
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
        // Bypasses UcpConfigService, so the type gate has to be applied here too.
        $rows = $this->connection->fetchAllAssociative(\sprintf(
            'SELECT LOWER(HEX(config.sales_channel_id)) AS sales_channel_id,
                    LOWER(HEX(sales_channel.type_id)) AS type_id,
                    config.config_json
             FROM `%s` AS config
             INNER JOIN sales_channel ON sales_channel.id = config.sales_channel_id',
            self::UCP_CONFIG_TABLE,
        ));

        $typeIdBySalesChannelId = [];
        foreach ($rows as $row) {
            $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
            if (!\is_array($config) || ($config['active'] ?? false) !== true) {
                continue;
            }

            $salesChannelId = (string) ($row['sales_channel_id'] ?? '');
            if (Uuid::isValid($salesChannelId)) {
                $typeIdBySalesChannelId[$salesChannelId] = (string) ($row['type_id'] ?? '');
            }
        }

        // Without a container (install) the built-in classification stands in for the resolver.
        $typeClassificationBySalesChannelId = null === $this->salesChannelTypeResolver
            ? array_map(SalesChannelTypeClassification::forTypeId(...), $typeIdBySalesChannelId)
            : $this->salesChannelTypeResolver->resolveMany(array_keys($typeIdBySalesChannelId));

        return array_keys(array_filter(
            $typeClassificationBySalesChannelId,
            static fn (SalesChannelTypeClassification $typeClassification): bool => $typeClassification->isTransactional(),
        ));
    }
}
