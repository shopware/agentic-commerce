<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

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

    public function testSaveConfigMergesPartialPayloadOverStoredConfig(): void
    {
        // Fields managed via console (signature policy, allowlists) are stored;
        // the admin then saves only the Exposure subset — the console-managed
        // fields must survive the merge (they are not in the payload).
        $repository = new InMemoryUcpConfigRepository([
            'sales-channel-a' => UcpConfig::fromArray([
                'active' => true,
                'signaturePolicy' => 'log',
                'agentAllowlist' => ['agent.example'],
                'enabledCapabilities' => ['catalog'],
            ]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $service = new UcpConfigService($repository, $legacyStore);

        $service->saveConfig([
            'active' => true,
            'enabledCapabilities' => ['catalog', 'cart'],
            'enabledTransports' => ['rest', 'a2a'],
        ], 'sales-channel-a');

        $stored = $repository->find('sales-channel-a');
        static::assertNotNull($stored);
        // Updated by the payload:
        static::assertSame(['catalog', 'cart'], $stored->enabledCapabilities);
        static::assertSame(['rest', 'a2a'], $stored->enabledTransports);
        // Preserved because the payload omitted them:
        static::assertSame('log', $stored->signaturePolicy);
        static::assertSame(['agent.example'], $stored->agentAllowlist);
    }

    public function testSaveConfigAcceptsLocalHttpWebhookOverridesWhenExplicitlyAllowed(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $service = new UcpConfigService($repository, $legacyStore, null, null, true);

        $config = $service->saveConfig([
            'agentAllowlist' => ['sw66.localhost'],
            'webhookUrlOverride' => 'http://sw66.localhost:8088/ucp/webhook',
        ], 'sales-channel-a');

        static::assertSame('http://sw66.localhost:8088/ucp/webhook', $config->webhookUrlOverride);
    }

    public function testItServesAStoredActiveConfigAsDisabledForAnIneligibleSalesChannel(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'feed-channel' => UcpConfig::fromArray([
                'active' => true,
                'signaturePolicy' => 'log',
                'enabledCapabilities' => ['catalog'],
            ]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $service = new UcpConfigService($repository, $legacyStore, null, null, false, $this->typeResolver(SalesChannelTypeClassification::ProductComparison));

        $config = $service->getConfig('feed-channel');

        static::assertFalse($config->active);
        static::assertSame('log', $config->signaturePolicy);
        static::assertSame(['catalog'], $config->enabledCapabilities);
        $stored = $repository->find('feed-channel');
        static::assertNotNull($stored);
        static::assertTrue($stored->active);
    }

    public function testItNeitherBackfillsNorEnablesCoreAgenticFilesForAnIneligibleSalesChannel(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static fn (string $key): mixed => 'SwagAgenticCommerce.config.active' === $key ? true : null,
        );
        $bridge = new RecordingAgenticFilesCoreBridge();
        $service = new UcpConfigService($repository, $legacyStore, $bridge, null, false, $this->typeResolver(SalesChannelTypeClassification::ProductComparison));

        $config = $service->getConfig('feed-channel');

        static::assertFalse($config->active);
        static::assertSame([], $bridge->enabledSalesChannelIds);
        static::assertNull($repository->find('feed-channel'));
    }

    public function testItRefusesToActivateUcpForAnIneligibleSalesChannel(): void
    {
        $repository = new InMemoryUcpConfigRepository();
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $service = new UcpConfigService($repository, $legacyStore, null, null, false, $this->typeResolver(SalesChannelTypeClassification::ProductComparison));

        try {
            $service->saveConfig(['active' => true], 'feed-channel');
            static::fail('Activating UCP on a channel that cannot sell must be refused.');
        } catch (UcpConfigException $exception) {
            static::assertSame(UcpConfigException::SALES_CHANNEL_TYPE_NOT_SUPPORTED, $exception->getErrorCode());
        }

        static::assertNull($repository->find('feed-channel'));
    }

    public function testItStillStoresAnInactiveConfigForAnIneligibleSalesChannel(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'feed-channel' => UcpConfig::fromArray(['active' => true]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $service = new UcpConfigService($repository, $legacyStore, null, null, false, $this->typeResolver(SalesChannelTypeClassification::ProductComparison));

        $config = $service->saveConfig(['active' => false], 'feed-channel');

        static::assertFalse($config->active);
        $stored = $repository->find('feed-channel');
        static::assertNotNull($stored);
        static::assertFalse($stored->active);
    }

    public function testItDisablesIneligibleSalesChannelsInBulkWithOneLookup(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'storefront-channel' => UcpConfig::fromArray(['active' => true]),
            'feed-channel' => UcpConfig::fromArray(['active' => true]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);

        $typeResolver = $this->createMock(AbstractSalesChannelTypeResolver::class);
        $typeResolver->expects(static::once())
            ->method('resolveMany')
            ->willReturnCallback(static fn (array $salesChannelIds): array => array_combine(
                $salesChannelIds,
                array_map(
                    static fn (string $salesChannelId): SalesChannelTypeClassification => 'storefront-channel' === $salesChannelId
                        ? SalesChannelTypeClassification::Storefront
                        : SalesChannelTypeClassification::ProductComparison,
                    $salesChannelIds,
                ),
            ));

        $service = new UcpConfigService($repository, $legacyStore, null, null, false, $typeResolver);
        $configs = $service->getConfigs(['storefront-channel', 'feed-channel']);

        static::assertTrue($configs['storefront-channel']->active);
        static::assertFalse($configs['feed-channel']->active);
    }

    public function testItSkipsTheSalesChannelLookupForAnInactiveConfig(): void
    {
        $repository = new InMemoryUcpConfigRepository([
            'storefront-channel' => UcpConfig::fromArray(['active' => false]),
        ]);
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $typeResolver = $this->createMock(AbstractSalesChannelTypeResolver::class);
        $typeResolver->expects(static::never())->method('resolve');

        $service = new UcpConfigService($repository, $legacyStore, null, null, false, $typeResolver);

        static::assertFalse($service->getConfig('storefront-channel')->active);
    }

    /**
     * The SDK's UrlSafetyValidator is built once, when the container is, from the union of every
     * channel's allowlists. Its consumers -- profile fetcher, key directory fetcher, order webhook
     * dispatcher -- are shared services that hold it, and it is final with a readonly host list,
     * so nothing short of a new container picks up an edit. A web request gets one; a
     * `messenger:consume` worker would keep refusing an outbound webhook to a host allowlisted
     * minutes ago for as long as it runs. Same signal PluginLifecycleService raises on install.
     */
    public function testAnAllowlistChangeAsksTheMessengerWorkersToRestart(): void
    {
        $pool = new RecordingRestartSignalCachePool();
        $service = new UcpConfigService(new InMemoryUcpConfigRepository(), $this->createMock(LegacyConfigStoreInterface::class), null, null, false, null, $pool);

        $service->saveConfig(['platformAllowlist' => ['agent.example.com']], 'sales-channel-a');

        static::assertSame([StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY], $pool->savedKeys);
    }

    /**
     * Restarting every worker whenever someone toggles exposure would be a worse trade than the
     * one the signal fixes, so only the lists that reach the validator raise it.
     */
    public function testSavingSomethingOtherThanAnAllowlistLeavesTheWorkersAlone(): void
    {
        $pool = new RecordingRestartSignalCachePool();
        $repository = new InMemoryUcpConfigRepository([
            'sales-channel-a' => UcpConfig::fromArray(['platformAllowlist' => ['agent.example.com']]),
        ]);
        $service = new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class), null, null, false, null, $pool);

        $service->saveConfig(['enabledCapabilities' => ['catalog']], 'sales-channel-a');

        static::assertSame([], $pool->savedKeys);
    }

    private function typeResolver(SalesChannelTypeClassification $class): AbstractSalesChannelTypeResolver
    {
        $typeResolver = $this->createMock(AbstractSalesChannelTypeResolver::class);
        $typeResolver->method('resolve')->willReturn($class);
        $typeResolver->method('resolveMany')->willReturnCallback(
            static fn (array $salesChannelIds): array => array_fill_keys($salesChannelIds, $class),
        );

        return $typeResolver;
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

/**
 * The messenger restart-signal pool, recording which keys were written rather than caching.
 *
 * @internal
 */
final class RecordingRestartSignalCachePool implements CacheItemPoolInterface
{
    /** @var list<string> */
    public array $savedKeys = [];

    public function getItem(string $key): CacheItemInterface
    {
        return new class($key) implements CacheItemInterface {
            private mixed $value = null;

            public function __construct(private readonly string $key)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function get(): mixed
            {
                return $this->value;
            }

            public function isHit(): bool
            {
                return false;
            }

            public function set(mixed $value): static
            {
                $this->value = $value;

                return $this;
            }

            public function expiresAt(?\DateTimeInterface $expiration): static
            {
                return $this;
            }

            public function expiresAfter(\DateInterval|int|null $time): static
            {
                return $this;
            }
        };
    }

    /**
     * @param array<int, string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return false;
    }

    public function clear(): bool
    {
        return true;
    }

    public function deleteItem(string $key): bool
    {
        return true;
    }

    /**
     * @param array<int, string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->savedKeys[] = $item->getKey();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
