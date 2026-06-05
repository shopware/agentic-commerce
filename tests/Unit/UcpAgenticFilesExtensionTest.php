<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Twig\UcpAgenticFilesExtension;

final class UcpAgenticFilesExtensionTest extends TestCase
{
    public function testItExposesConfiguredSalesChannelAsActive(): void
    {
        $extension = new UcpAgenticFilesExtension($this->createConfigService([
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ]));

        static::assertTrue($extension->isUcpActive('sales-channel-a'));
    }

    public function testItResolvesSalesChannelEntityId(): void
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-a');

        $extension = new UcpAgenticFilesExtension($this->createConfigService([
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ]));

        static::assertTrue($extension->isUcpActive($salesChannel));
    }

    public function testItDoesNotExposeInactiveOrMissingSalesChannels(): void
    {
        $extension = new UcpAgenticFilesExtension($this->createConfigService([
            'sales-channel-a' => UcpConfig::fromArray(['active' => false]),
        ]));

        static::assertFalse($extension->isUcpActive('sales-channel-a'));
        static::assertFalse($extension->isUcpActive('sales-channel-b'));
        static::assertFalse($extension->isUcpActive(null));
    }

    public function testItRegistersTwigFunction(): void
    {
        $extension = new UcpAgenticFilesExtension($this->createConfigService([]));

        static::assertSame('swag_agentic_commerce_ucp_active', $extension->getFunctions()[0]->getName());
    }

    /**
     * @param array<string, UcpConfig> $configs
     */
    private function createConfigService(array $configs): UcpConfigService
    {
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturn(null);

        return new UcpConfigService(new UcpAgenticFilesExtensionConfigRepository($configs), $legacyStore);
    }
}

final class UcpAgenticFilesExtensionConfigRepository implements UcpConfigRepositoryInterface
{
    /**
     * @param array<string, UcpConfig> $configs
     */
    public function __construct(private array $configs)
    {
    }

    public function find(string $salesChannelId): ?UcpConfig
    {
        return $this->configs[$salesChannelId] ?? null;
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array<string, UcpConfig>
     */
    public function findMany(array $salesChannelIds): array
    {
        return array_filter(
            $this->configs,
            static fn (string $salesChannelId): bool => \in_array($salesChannelId, $salesChannelIds, true),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    public function save(string $salesChannelId, UcpConfig $config): void
    {
        $this->configs[$salesChannelId] = $config;
    }
}
