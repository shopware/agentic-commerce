<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles;

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
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;

/**
 * @internal
 */
#[CoversClass(SalesChannelBaseUrlResolver::class)]
final class SalesChannelBaseUrlResolverTest extends TestCase
{
    public function testItResolvesTheCurrentDomain(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();
        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://shop.example.com/en/'),
        ]);

        $resolver = $this->resolver($salesChannel);

        static::assertSame(
            'https://shop.example.com/en',
            $resolver->resolve($this->context($salesChannelId, $currentDomainId)),
        );
    }

    public function testItFallsBackToTheFirstDomainWhenNoDomainMatchesTheContext(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://fallback.example.com/'),
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://other.example.com/'),
        ]);

        $resolver = $this->resolver($salesChannel);

        static::assertSame(
            'https://fallback.example.com',
            $resolver->resolve($this->context($salesChannelId, Uuid::randomHex())),
        );
    }

    public function testItReturnsNullWhenTheSalesChannelHasNoDomains(): void
    {
        $salesChannelId = Uuid::randomHex();
        $resolver = $this->resolver($this->salesChannel($salesChannelId, []));

        static::assertNull($resolver->resolve($this->context($salesChannelId, null)));
    }

    public function testResolveFromSalesChannelReturnsNullForNullSalesChannel(): void
    {
        $resolver = new SalesChannelBaseUrlResolver($this->createMock(EntityRepository::class));

        static::assertNull($resolver->resolveFromSalesChannel(null, $this->context(Uuid::randomHex(), null)));
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

    private function resolver(SalesChannelEntity $salesChannel): SalesChannelBaseUrlResolver
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

        return new SalesChannelBaseUrlResolver($repository);
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
