<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

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
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;

/**
 * @internal
 */
#[CoversClass(FallbackAgenticFileRenderer::class)]
final class FallbackAgenticFileRendererTest extends TestCase
{
    public function testItBuildsContextFromCurrentDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();
        $salesChannel = $this->salesChannel([
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://SHOP.Example.COM/en/'),
        ]);

        static::assertSame([
            'baseUrl' => 'https://SHOP.Example.COM/en',
            'publisher' => 'shop.example.com',
        ], $this->buildSalesChannelFileContext($salesChannel, $this->context($currentDomainId)));
    }

    public function testItFallsBackToFirstDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannel = $this->salesChannel([
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://fallback.example.com/'),
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://other.example.com/'),
        ]);

        static::assertSame([
            'baseUrl' => 'https://fallback.example.com',
            'publisher' => 'fallback.example.com',
        ], $this->buildSalesChannelFileContext($salesChannel, $this->context(Uuid::randomHex())));
    }

    public function testItReturnsNullContextWithoutDomains(): void
    {
        static::assertSame([
            'baseUrl' => null,
            'publisher' => null,
        ], $this->buildSalesChannelFileContext(new SalesChannelEntity(), $this->context(null)));
    }

    public function testGetSalesChannelBaseUrlResolvesTheCurrentDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();
        $salesChannel = $this->salesChannel([
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://shop.example.com/en/'),
        ]);

        $renderer = $this->rendererFor($salesChannel);

        static::assertSame(
            'https://shop.example.com/en',
            $renderer->getSalesChannelBaseUrl($this->context($currentDomainId)),
        );
    }

    public function testGetSalesChannelBaseUrlReturnsNullWithoutDomains(): void
    {
        $renderer = $this->rendererFor($this->salesChannel([]));

        static::assertNull($renderer->getSalesChannelBaseUrl($this->context(null)));
    }

    private function rendererFor(SalesChannelEntity $salesChannel): FallbackAgenticFileRenderer
    {
        // A collection keys its members by unique identifier, so the entity needs an id.
        $salesChannel->setId(Uuid::randomHex());
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

    /**
     * @return array{baseUrl: string|null, publisher: string|null}
     */
    private function buildSalesChannelFileContext(SalesChannelEntity $salesChannel, SalesChannelContext $context): array
    {
        $renderer = (new \ReflectionClass(FallbackAgenticFileRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FallbackAgenticFileRenderer::class, 'buildSalesChannelFileContext');
        $result = $method->invoke($renderer, $salesChannel, $context);

        static::assertIsArray($result);

        $baseUrl = $result['baseUrl'] ?? null;
        $publisher = $result['publisher'] ?? null;

        if (null !== $baseUrl && !\is_string($baseUrl)) {
            static::fail('Expected baseUrl to be null or string.');
        }

        if (null !== $publisher && !\is_string($publisher)) {
            static::fail('Expected publisher to be null or string.');
        }

        return [
            'baseUrl' => $baseUrl,
            'publisher' => $publisher,
        ];
    }

    private function context(?string $domainId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        // getSalesChannelBaseUrl() loads the sales channel by id, and core rejects an empty
        // criteria id, so a valid id is required even though the stubbed repository ignores it.
        $context->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $context->method('getDomainId')->willReturn($domainId);

        return $context;
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     */
    private function salesChannel(array $domains): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
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
}
