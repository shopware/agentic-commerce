<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Ucp\Onboarding;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Onboarding\DoctrineDbalOnboardingMetrics;

/**
 * The counts are SQL, so they get a real connection rather than a mocked one: the
 * joins against product_visibility and the live-version filter are the behaviour
 * under test, and a mock would only restate the query.
 *
 * @internal
 */
final class DoctrineDbalOnboardingMetricsTest extends TestCase
{
    private Connection $connection;

    private DoctrineDbalOnboardingMetrics $metrics;

    protected function setUp(): void
    {
        $this->connection = KernelLifecycleManager::getConnection();
        $this->connection->beginTransaction();
        $this->metrics = new DoctrineDbalOnboardingMetrics($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
    }

    public function testItCountsOnlyActiveProductsVisibleInTheChannel(): void
    {
        $salesChannelId = $this->firstStorefrontSalesChannelId();

        $before = $this->metrics->activeProductCounts([$salesChannelId])[$salesChannelId] ?? 0;

        $active = $this->createProduct(active: true);
        $inactive = $this->createProduct(active: false);
        $this->makeVisible($active, $salesChannelId);
        $this->makeVisible($inactive, $salesChannelId);

        $after = $this->metrics->activeProductCounts([$salesChannelId])[$salesChannelId] ?? 0;

        self::assertSame($before + 1, $after, 'Only the active product should raise the count.');
    }

    public function testAChannelWithoutVisibleProductsIsAbsentRatherThanZero(): void
    {
        $emptyChannelId = Uuid::randomHex();

        self::assertSame([], $this->metrics->activeProductCounts([$emptyChannelId]));
    }

    public function testItAsksForNothingWhenGivenNoChannels(): void
    {
        self::assertSame([], $this->metrics->activeProductCounts([]));
    }

    public function testItCountsActiveAgenticCommerceSalesChannels(): void
    {
        // The stock test database ships none, so the count proves the type filter
        // rather than an arbitrary number.
        self::assertSame(0, $this->metrics->agenticSalesChannelCount());
    }

    private function firstStorefrontSalesChannelId(): string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :typeId AND active = 1 LIMIT 1',
            ['typeId' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)],
        );
        self::assertIsString($id, 'Expected an active storefront sales channel in the test database.');

        return $id;
    }

    private function createProduct(bool $active): string
    {
        $id = Uuid::randomHex();
        $taxId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM tax LIMIT 1');
        self::assertIsString($taxId);

        $this->connection->insert('product', [
            'id' => Uuid::fromHexToBytes($id),
            'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'product_number' => 'metrics-'.$id,
            'stock' => 1,
            'tax_id' => Uuid::fromHexToBytes($taxId),
            'active' => $active ? 1 : 0,
            'available' => 1,
            'is_closeout' => 0,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    private function makeVisible(string $productId, string $salesChannelId): void
    {
        $this->connection->insert('product_visibility', [
            'id' => Uuid::randomBytes(),
            'product_id' => Uuid::fromHexToBytes($productId),
            'product_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'sales_channel_id' => Uuid::fromHexToBytes($salesChannelId),
            'visibility' => 30,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
