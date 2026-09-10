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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;

/**
 * @internal
 */
#[CoversClass(SalesChannelBaseUrlResolver::class)]
final class SalesChannelBaseUrlResolverTest extends TestCase
{
    public function testItUsesTheDomainsAlreadyLoadedOnTheContextWithoutQuerying(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();
        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://first.example.com/'),
            $this->domain($currentDomainId, $salesChannelId, 'https://shop.example.com/en/'),
        ]);

        // Domains are present on the context, so the repository must not be touched.
        $resolver = $this->resolverWithoutRepositoryAccess();

        static::assertSame(
            'https://shop.example.com/en',
            $resolver->resolve($this->context($salesChannel, $currentDomainId)),
        );
    }

    public function testItLoadsTheCurrentDomainWhenTheContextDomainsDoNotContainIt(): void
    {
        $salesChannelId = Uuid::randomHex();
        $currentDomainId = Uuid::randomHex();

        // The context carries a domain, but not the one the request came in on.
        $salesChannel = $this->salesChannel($salesChannelId, [
            $this->domain(Uuid::randomHex(), $salesChannelId, 'https://other.example.com/'),
        ]);

        $resolver = $this->resolverLoading(new SalesChannelDomainCollection([
            $this->domain($currentDomainId, $salesChannelId, 'https://shop.example.com/'),
        ]));

        static::assertSame(
            'https://shop.example.com',
            $resolver->resolve($this->context($salesChannel, $currentDomainId)),
        );
    }

    public function testItLoadsTheCurrentDomainWhenTheContextDidNotCarryDomains(): void
    {
        $salesChannelId = Uuid::randomHex();
        $domainId = Uuid::randomHex();
        $domains = new SalesChannelDomainCollection([
            $this->domain($domainId, $salesChannelId, 'https://shop.example.com/'),
        ]);

        // The context's sales channel has no domains association (null), triggering the lazy load.
        $resolver = $this->resolverLoading($domains);

        static::assertSame(
            'https://shop.example.com',
            $resolver->resolve($this->context(new SalesChannelEntity(), $domainId)),
        );
    }

    public function testItReturnsNullWhenDomainsAreNotLoadedAndNoDomainIdIsPresent(): void
    {
        // No domains on the context and no domain id to load one by: nothing to query, nothing to resolve.
        $resolver = $this->resolverWithoutRepositoryAccess();

        static::assertNull($resolver->resolve($this->context(new SalesChannelEntity(), null)));
    }

    public function testItReturnsNullWhenTheSalesChannelHasNoDomains(): void
    {
        $salesChannel = $this->salesChannel(Uuid::randomHex(), []);

        $resolver = $this->resolverWithoutRepositoryAccess();

        static::assertNull($resolver->resolve($this->context($salesChannel, null)));
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

    private function resolverWithoutRepositoryAccess(): SalesChannelBaseUrlResolver
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('search');

        return new SalesChannelBaseUrlResolver($repository);
    }

    private function resolverLoading(SalesChannelDomainCollection $domains): SalesChannelBaseUrlResolver
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                $domains->count(),
                $domains,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelBaseUrlResolver($repository);
    }

    private function context(SalesChannelEntity $salesChannel, ?string $domainId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $context->method('getDomainId')->willReturn($domainId);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
