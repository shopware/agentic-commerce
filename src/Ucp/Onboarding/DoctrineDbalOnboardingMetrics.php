<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Two aggregate counts for the readiness page, read straight from the database.
 *
 * DAL criteria would need one search per channel for the product counts; this
 * is a single grouped query. Both counts are plugin-owned admin metadata rather
 * than customer-facing runtime, which is the documented exception to the
 * Store-API rule in AGENTS.md.
 *
 * @internal
 */
#[Package('framework')]
final class DoctrineDbalOnboardingMetrics implements OnboardingMetricsInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function activeProductCounts(array $salesChannelIds): array
    {
        if ([] === $salesChannelIds) {
            return [];
        }

        $binaryIds = array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), $salesChannelIds);

        /** @var list<array{sales_channel_id: string, product_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pv.sales_channel_id)) AS sales_channel_id,
                    COUNT(DISTINCT pv.product_id) AS product_count
             FROM product_visibility pv
             INNER JOIN product p
                 ON p.id = pv.product_id
                AND p.version_id = pv.product_version_id
             WHERE pv.sales_channel_id IN (:salesChannelIds)
               AND p.version_id = :liveVersion
               AND p.active = 1
             GROUP BY pv.sales_channel_id',
            [
                'salesChannelIds' => $binaryIds,
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            ['salesChannelIds' => ArrayParameterType::BINARY],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['sales_channel_id']] = (int) $row['product_count'];
        }

        return $counts;
    }

    public function agenticSalesChannelCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM sales_channel WHERE type_id = :typeId AND active = 1',
            ['typeId' => Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE)],
        );
    }
}
