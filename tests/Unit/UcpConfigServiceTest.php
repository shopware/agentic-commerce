<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

/** @internal */
#[CoversClass(UcpConfigService::class)]
final class UcpConfigServiceTest extends TestCase
{
    public function testItLoadsPersistedSalesChannelConfigFromRepository(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'sales-channel-a' => UcpConfig::fromArray([
                'active' => true,
                'enabledCapabilities' => ['catalog', 'checkout'],
            ]),
        ]);

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->expects(static::never())->method('get');

        $service = new UcpConfigService($repository, $legacyStore);

        static::assertTrue($service->getConfig('sales-channel-a')->active);
        static::assertSame(['catalog', 'checkout'], $service->getConfig('sales-channel-a')->enabledCapabilities);
    }

    public function testItBackfillsLegacySystemConfigForSalesChannelScopedCompatibility(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static function (string $key, ?string $salesChannelId): mixed {
                static::assertSame('sales-channel-b', $salesChannelId);

                return match ($key) {
                    'SwagAgenticCommerce.config.active' => true,
                    'SwagAgenticCommerce.config.signaturePolicy' => 'log',
                    'SwagAgenticCommerce.config.catalogResultLimit' => 7,
                    'SwagAgenticCommerce.config.enabledCapabilities' => ['catalog', 'cart'],
                    default => null,
                };
            },
        );

        $service = new UcpConfigService($repository, $legacyStore);
        $config = $service->getConfig('sales-channel-b');

        static::assertTrue($config->active);
        static::assertSame('log', $config->signaturePolicy);
        static::assertSame(7, $config->catalogResultLimit);
        $persistedConfig = $repository->find('sales-channel-b');
        static::assertNotNull($persistedConfig);
        static::assertTrue($persistedConfig->active);
    }

    public function testItLoadsConfigSummariesInBulk(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ]);

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static function (string $key, ?string $salesChannelId): mixed {
                if ('sales-channel-b' !== $salesChannelId) {
                    return null;
                }

                return match ($key) {
                    'SwagAgenticCommerce.config.active' => false,
                    'SwagAgenticCommerce.config.enabledCapabilities' => ['catalog'],
                    default => null,
                };
            },
        );

        $service = new UcpConfigService($repository, $legacyStore);
        $configs = $service->getConfigs(['sales-channel-a', 'sales-channel-b']);

        static::assertCount(2, $configs);
        static::assertTrue($configs['sales-channel-a']->active);
        static::assertSame(['catalog'], $configs['sales-channel-b']->enabledCapabilities);
    }

    public function testItEnablesCoreAgenticFilesWhenSalesChannelConfigIsSavedActive(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge);

        $service->saveConfig(['active' => true], 'sales-channel-a');

        static::assertSame(['sales-channel-a'], $bridge->enabledSalesChannelIds);
    }

    public function testItDoesNotEnableCoreAgenticFilesWhenSalesChannelConfigIsSavedInactive(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge);

        $service->saveConfig(['active' => false], 'sales-channel-a');

        static::assertSame([], $bridge->enabledSalesChannelIds);
    }

    public function testItEnablesCoreAgenticFilesWhenActiveLegacyConfigIsBackfilledOnRead(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static fn (string $key): mixed => 'SwagAgenticCommerce.config.active' === $key ? true : null,
        );
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge);

        $service->getConfig('sales-channel-b');

        static::assertSame(['sales-channel-b'], $bridge->enabledSalesChannelIds);
    }

    public function testItDoesNotEnableCoreAgenticFilesWhenInactiveLegacyConfigIsBackfilledOnRead(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static fn (string $key): mixed => 'SwagAgenticCommerce.config.signaturePolicy' === $key ? 'log' : null,
        );
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge);

        $service->getConfig('sales-channel-b');

        static::assertSame([], $bridge->enabledSalesChannelIds);
    }

    public function testItDoesNotEnableCoreAgenticFilesWhenConfigAlreadyPersisted(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'sales-channel-a' => UcpConfig::fromArray(['active' => true]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->expects(static::never())->method('get');
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge);

        $service->getConfig('sales-channel-a');

        static::assertSame([], $bridge->enabledSalesChannelIds);
    }
}

/** @internal */
final class InMemoryUcpConfigRepository implements UcpConfigRepositoryInterface
{
    /**
     * @param array<string, UcpConfig> $configs
     */
    public function __construct(
        private array $configs = [],
    ) {
    }

    public function find(string $salesChannelId): ?UcpConfig
    {
        return $this->configs[$salesChannelId] ?? null;
    }

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

/** @internal */
final class RecordingAgenticFilesCoreBridge implements AgenticFilesCoreBridgeInterface
{
    /**
     * @var list<string>
     */
    public array $enabledSalesChannelIds = [];

    public function enableForSalesChannel(string $salesChannelId): void
    {
        $this->enabledSalesChannelIds[] = $salesChannelId;
    }

    public function syncActiveUcpSalesChannels(): void
    {
    }
}
