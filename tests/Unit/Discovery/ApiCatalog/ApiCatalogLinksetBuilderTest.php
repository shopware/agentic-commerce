<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Discovery\ApiCatalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Discovery\ApiCatalog\ApiCatalogLinksetBuilder;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;

#[Package('discovery')]
#[CoversClass(ApiCatalogLinksetBuilder::class)]
final class ApiCatalogLinksetBuilderTest extends TestCase
{
    private const BASE_URI = 'https://shop.example';

    public static function setUpBeforeClass(): void
    {
        // supportsStoreApiMcp() treats the experimental MCP_SERVER flag as part of availability.
        $_SERVER['MCP_SERVER'] = '1';
    }

    public static function tearDownAfterClass(): void
    {
        unset($_SERVER['MCP_SERVER']);
    }

    #[Test]
    public function itAnchorsTheCatalogOnItsOwnUrlAndItemisesTheApis(): void
    {
        $linkset = $this->build(UcpConfig::fromArray(['active' => true]));

        static::assertSame(self::BASE_URI.'/.well-known/api-catalog', $linkset[0]['anchor']);
        static::assertSame([
            ['href' => self::BASE_URI.'/ucp', 'title' => 'Universal Commerce Protocol'],
            ['href' => self::BASE_URI.'/store-api', 'title' => 'Shopware Store API'],
        ], $linkset[0]['item']);
    }

    #[Test]
    public function itDescribesTheUcpProfileAsServiceMeta(): void
    {
        $linkset = $this->build(UcpConfig::fromArray(['active' => true]));

        static::assertSame([
            'anchor' => self::BASE_URI.'/ucp',
            'service-meta' => [[
                'href' => self::BASE_URI.'/.well-known/ucp',
                'type' => 'application/json',
                'title' => 'UCP platform profile',
            ]],
        ], $linkset[1]);
    }

    #[Test]
    public function itDescribesTheStoreApiWithItsOpenApiDescription(): void
    {
        $linkset = $this->build(UcpConfig::fromArray(['active' => true]));

        static::assertSame([
            'anchor' => self::BASE_URI.'/store-api',
            'service-desc' => [[
                'href' => self::BASE_URI.'/store-api/_info/openapi3.json',
                'type' => 'application/json',
                'title' => 'Store API OpenAPI 3 description',
            ]],
        ], $this->contextFor($linkset, self::BASE_URI.'/store-api'));
    }

    #[Test]
    public function itListsTheAgentCardOnlyWhenTheA2aTransportIsEnabled(): void
    {
        $withA2a = $this->build(UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest', 'a2a'],
        ]));

        static::assertSame([[
            'href' => self::BASE_URI.'/.well-known/agent-card.json',
            'type' => 'application/json',
            'title' => 'A2A agent card',
        ]], $this->contextFor($withA2a, self::BASE_URI.'/ucp/a2a')['service-meta'] ?? null);

        $withoutA2a = $this->build(UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest'],
        ]));

        static::assertNull($this->contextFor($withoutA2a, self::BASE_URI.'/ucp/a2a'));
    }

    #[Test]
    public function itListsTheMcpEndpointOnlyWhenTheLaneServesIt(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest', 'mcp'],
        ]);

        $onMcpLane = $this->build($config, shopwareVersion: '6.7.0.0');

        static::assertContains(
            ['href' => self::BASE_URI.'/ucp/mcp', 'title' => 'UCP Model Context Protocol endpoint'],
            $onMcpLane[0]['item'],
        );

        $onOlderLane = $this->build($config, shopwareVersion: '6.6.0.0');

        static::assertSame([
            self::BASE_URI.'/ucp',
            self::BASE_URI.'/store-api',
        ], array_column($onOlderLane[0]['item'], 'href'));
    }

    #[Test]
    public function itDoesNotListTheMcpEndpointWhenTheTransportIsDisabled(): void
    {
        $linkset = $this->build(
            UcpConfig::fromArray(['active' => true, 'enabledTransports' => ['rest']]),
            shopwareVersion: '6.7.0.0',
        );

        static::assertSame([
            self::BASE_URI.'/ucp',
            self::BASE_URI.'/store-api',
        ], array_column($linkset[0]['item'], 'href'));
    }

    #[Test]
    public function itBuildsEveryUriFromThePinnedProfileDomain(): void
    {
        $linkset = $this->build(UcpConfig::fromArray([
            'active' => true,
            'profileDomain' => 'https://agents.example',
        ]));

        static::assertSame('https://agents.example/.well-known/api-catalog', $linkset[0]['anchor']);
        static::assertSame([
            'https://agents.example/ucp',
            'https://agents.example/store-api',
        ], array_column($linkset[0]['item'], 'href'));
        static::assertSame(
            'https://agents.example/.well-known/ucp',
            $linkset[1]['service-meta'][0]['href'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(UcpConfig $config, string $shopwareVersion = '6.7.0.0'): array
    {
        $builder = new ApiCatalogLinksetBuilder(new ShopwareVersionDetector($shopwareVersion));

        return $builder->build($config, self::BASE_URI)['linkset'];
    }

    /**
     * @param list<array<string, mixed>> $linkset
     *
     * @return array<string, mixed>|null
     */
    private function contextFor(array $linkset, string $anchor): ?array
    {
        foreach ($linkset as $context) {
            if ($anchor === ($context['anchor'] ?? null)) {
                return $context;
            }
        }

        return null;
    }
}
