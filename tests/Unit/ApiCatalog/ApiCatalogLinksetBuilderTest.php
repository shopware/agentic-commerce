<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\ApiCatalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\ApiCatalog\ApiCatalogLinksetBuilder;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;

/**
 * @internal
 */
#[CoversClass(ApiCatalogLinksetBuilder::class)]
final class ApiCatalogLinksetBuilderTest extends TestCase
{
    public function testItLinksUcpProfileAndStoreApiForTheCurrentDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();

        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://shop.example.com/en/'),
        ]);

        $result = $this->builder($salesChannel)->build($this->context($salesChannelId, $currentDomainId));

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => 'https://shop.example.com/en/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => 'https://shop.example.com/en/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => 'https://shop.example.com/en/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $result);
    }

    public function testItFallsBackToTheFirstDomainWhenNoDomainMatchesTheContext(): void
    {
        $salesChannelId = Uuid::randomHex();

        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://fallback.example.com/'),
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://other.example.com/'),
        ]);

        $result = $this->builder($salesChannel)->build($this->context($salesChannelId, Uuid::randomHex()));

        static::assertSame(
            'https://fallback.example.com/.well-known/ucp',
            $result['linkset'][0]['service-meta'][0]['href'],
        );
        static::assertSame(
            'https://fallback.example.com/store-api',
            $result['linkset'][0]['item'][0]['href'],
        );
    }

    public function testItEmitsRootRelativeReferencesWhenNoDomainIsAvailable(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannel = $this->salesChannel($salesChannelId, []);

        $result = $this->builder($salesChannel)->build($this->context($salesChannelId, null));

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => '/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => '/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => '/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $result);
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     */
    private function salesChannel(string $salesChannelId, array $domains): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);
        $salesChannel->setDomains(new SalesChannelDomainCollection($domains));

        return $salesChannel;
    }

    private function domain(string $id, string $salesChannelId, string $url): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId($id);
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId(Uuid::randomHex());
        $domain->setUrl($url);

        return $domain;
    }

    private function builder(SalesChannelEntity $salesChannel): ApiCatalogLinksetBuilder
    {
        return new ApiCatalogLinksetBuilder($this->fileRenderer($salesChannel));
    }

    /**
     * The base URL is resolved by the (final) fallback renderer; inject a stubbed
     * sales-channel repository so only its {@see FallbackAgenticFileRenderer::getSalesChannelBaseUrl}
     * path is exercised, without booting Twig/router dependencies.
     */
    private function fileRenderer(SalesChannelEntity $salesChannel): FallbackAgenticFileRenderer
    {
        $salesChannels = new SalesChannelCollection([$salesChannel]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel',
                $salesChannels->count(),
                $salesChannels,
                null,
                $criteria,
                $context,
            ),
        );

        $renderer = (new \ReflectionClass(FallbackAgenticFileRenderer::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(FallbackAgenticFileRenderer::class, 'salesChannelRepository'))
            ->setValue($renderer, $repository);

        return $renderer;
    }

    private function context(string $salesChannelId, ?string $domainId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getDomainId')->willReturn($domainId);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
