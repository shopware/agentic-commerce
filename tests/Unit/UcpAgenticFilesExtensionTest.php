<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Twig\UcpAgenticFilesExtension;

/** @internal */
#[CoversClass(UcpAgenticFilesExtension::class)]
final class UcpAgenticFilesExtensionTest extends TestCase
{
    /** @var array<string, UcpConfig> */
    private array $configs = [];

    private UcpAgenticFilesExtension $extension;

    protected function setUp(): void
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(fn (string $salesChannelId): ?UcpConfig => $this->configs[$salesChannelId] ?? null);
        $repository->method('findMany')->willReturnCallback(
            fn (array $salesChannelIds): array => array_filter(
                $this->configs,
                static fn (string $salesChannelId): bool => \in_array($salesChannelId, $salesChannelIds, true),
                \ARRAY_FILTER_USE_KEY,
            ),
        );

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturn(null);

        $this->extension = new UcpAgenticFilesExtension(new UcpConfigService($repository, $legacyStore));
    }

    public function testItExposesConfiguredSalesChannelAsActive(): void
    {
        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ];

        static::assertTrue($this->extension->isUcpActive('sales-channel-a'));
    }

    public function testItResolvesSalesChannelEntityId(): void
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-a');

        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ];

        static::assertTrue($this->extension->isUcpActive($salesChannel));
    }

    public function testItDoesNotExposeInactiveOrMissingSalesChannels(): void
    {
        $this->configs = [
            'sales-channel-a' => UcpConfig::fromArray(['active' => false]),
        ];

        static::assertFalse($this->extension->isUcpActive('sales-channel-a'));
        static::assertFalse($this->extension->isUcpActive('sales-channel-b'));
        static::assertFalse($this->extension->isUcpActive(null));
    }

    public function testItRegistersTwigFunction(): void
    {
        static::assertSame('swag_agentic_commerce_ucp_active', $this->extension->getFunctions()[0]->getName());
    }
}
