<?php

declare(strict_types=1);

/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Http;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Http\ConfiguredUrlSafetyValidatorFactory;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

/**
 * @internal
 */
#[CoversClass(ConfiguredUrlSafetyValidatorFactory::class)]
class ConfiguredUrlSafetyValidatorFactoryTest extends TestCase
{
    private const CHANNEL_WITH_CONFIG = 'aaaa3aa2e507367b683876dcf1f27301';
    private const CHANNEL_WITHOUT_CONFIG = 'bbbb3aa2e507367b683876dcf1f27302';

    public function testCreateUnionsChannelAndGlobalAllowlistsAndDomainHosts(): void
    {
        $factory = new ConfiguredUrlSafetyValidatorFactory(
            $this->connectionStub([self::CHANNEL_WITH_CONFIG, self::CHANNEL_WITHOUT_CONFIG], [
                'https://Agent-Shop.Agentic-Commerce-Lab.ai/',
                'http://localhost:8000',
            ]),
            $this->configService(),
        );

        $validator = $factory->create();

        static::assertSame(
            [
                'agent-shop.agentic-commerce-lab.ai',
                'harness.agent-shop.agentic-commerce-lab.ai',
                'localhost',
                'platform.example',
            ],
            $this->allowedHosts($validator),
        );
        static::assertFalse($this->developmentMode($validator));
    }

    public function testCreatePassesTheDevelopmentModeFlagThrough(): void
    {
        $factory = new ConfiguredUrlSafetyValidatorFactory($this->connectionStub([], []), $this->configService(), true);

        static::assertTrue($this->developmentMode($factory->create()));
    }

    public function testCreateSurvivesDatabaseFailuresWithAnEmptyAllowlist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('no db'));

        $factory = new ConfiguredUrlSafetyValidatorFactory($connection, $this->configService());

        static::assertSame([], $this->allowedHosts($factory->create()));
    }

    /**
     * @param list<string> $salesChannelIds
     * @param list<string> $domainUrls
     */
    private function connectionStub(array $salesChannelIds, array $domainUrls): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(static function (string $sql) use ($salesChannelIds, $domainUrls): array {
                return str_contains($sql, 'sales_channel_domain') ? $domainUrls : $salesChannelIds;
            });

        return $connection;
    }

    private function configService(): UcpConfigService
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('findMany')->willReturnCallback(static fn (array $ids): array => (
            \in_array(self::CHANNEL_WITH_CONFIG, $ids, true)
                ? [
                    self::CHANNEL_WITH_CONFIG => UcpConfig::fromArray([
                        'active' => true,
                        'remoteProfileAllowlist' => ['harness.agent-shop.agentic-commerce-lab.ai'],
                    ]),
                ]
                : []
        ));
        $repository->method('find')->willReturn(null);

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(static fn (string $key, ?string $salesChannelId): mixed => (
            null === $salesChannelId && 'SwagAgenticCommerce.config.platformAllowlist' === $key
                ? ['Platform.example']
                : null
        ));

        return new UcpConfigService($repository, $legacyStore);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(UrlSafetyValidator $validator): array
    {
        $property = new \ReflectionProperty($validator, 'allowedHosts');

        /** @var list<string> $hosts */
        $hosts = $property->getValue($validator);

        return $hosts;
    }

    private function developmentMode(UrlSafetyValidator $validator): bool
    {
        $property = new \ReflectionProperty($validator, 'profileFetchingDevelopmentMode');

        return (bool) $property->getValue($validator);
    }
}
