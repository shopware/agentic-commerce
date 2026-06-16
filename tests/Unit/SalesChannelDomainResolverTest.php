<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolverCacheInvalidator;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * @internal
 */
#[CoversClass(SalesChannelDomainResolver::class)]
#[CoversClass(SalesChannelDomainResolverCacheInvalidator::class)]
final class SalesChannelDomainResolverTest extends TestCase
{
    public function testItNarrowsDomainQueryByHostWithoutArbitraryLimit(): void
    {
        $repository = $this->domainRepository([$this->domain('domain-a', 'sales-channel-a', 'https://shop.example')], assertCriteria: static function (Criteria $criteria): void {
            static::assertNull($criteria->getLimit());
            static::assertCount(1, $criteria->getFilters());

            $filter = $criteria->getFilters()[0];
            static::assertInstanceOf(MultiFilter::class, $filter);
            static::assertSame(MultiFilter::CONNECTION_OR, $filter->getOperator());

            $containsFilters = $filter->getQueries();
            static::assertNotEmpty($containsFilters);
            static::assertContainsOnlyInstancesOf(ContainsFilter::class, $containsFilters);
            static::assertContains('://shop.example', array_map(
                static fn (ContainsFilter $containsFilter): string => (string) $containsFilter->getValue(),
                $containsFilters,
            ));
        });

        $resolver = new SalesChannelDomainResolver($repository);

        static::assertSame('sales-channel-a', $resolver->resolveByAbsoluteUri('https://shop.example/ucp/profile')?->salesChannelId);
    }

    public function testItResolvesDirectDomainHitBeforePrefixMatch(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-root', 'sales-channel-root', 'https://shop.example'),
            $this->domain('domain-shop', 'sales-channel-shop', 'https://shop.example/shop'),
        ]));

        $resolution = $resolver->resolveByAbsoluteUri('https://shop.example/shop');

        static::assertNotNull($resolution);
        static::assertSame('sales-channel-shop', $resolution->salesChannelId);
        static::assertSame('domain-shop', $resolution->domainId);
        static::assertSame('https://shop.example/shop', $resolution->baseUrl);
    }

    public function testItUsesLongestMatchingDomainUrlPrefix(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-root', 'sales-channel-root', 'https://shop.example'),
            $this->domain('domain-shop', 'sales-channel-shop', 'https://shop.example/shop'),
            $this->domain('domain-shop-de', 'sales-channel-shop-de', 'https://shop.example/shop/de'),
        ]));

        $resolution = $resolver->resolveByAbsoluteUri('https://shop.example/shop/de/cart');

        static::assertNotNull($resolution);
        static::assertSame('sales-channel-shop-de', $resolution->salesChannelId);
        static::assertSame('domain-shop-de', $resolution->domainId);
    }

    public function testItDoesNotMatchPartialPathSegments(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-shop', 'sales-channel-shop', 'https://shop.example/shop'),
        ]));

        static::assertNull($resolver->resolveByAbsoluteUri('https://shop.example/shopping/cart'));
        $resolution = $resolver->resolveByAbsoluteUri('https://shop.example/shop/cart');

        static::assertNotNull($resolution);
        static::assertSame('sales-channel-shop', $resolution->salesChannelId);
    }

    public function testItRequiresSchemeAndPortToMatchAbsoluteUris(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-http', 'sales-channel-http', 'http://shop.example'),
            $this->domain('domain-https-port', 'sales-channel-https-port', 'https://shop.example:8443'),
        ]));

        static::assertNull($resolver->resolveByAbsoluteUri('https://shop.example/cart'));
        static::assertNull($resolver->resolveByAbsoluteUri('https://shop.example:9443/cart'));
        $resolution = $resolver->resolveByAbsoluteUri('https://shop.example:8443/cart');

        static::assertNotNull($resolution);
        static::assertSame('sales-channel-https-port', $resolution->salesChannelId);
    }

    public function testItResolvesSameHostVirtualPathsToDifferentDomains(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-de', 'sales-channel-de', 'https://shop.example/de'),
            $this->domain('domain-en', 'sales-channel-en', 'https://shop.example/en'),
        ]));

        $germanResolution = $resolver->resolveByAbsoluteUri('https://shop.example/de/products');
        $englishResolution = $resolver->resolveByAbsoluteUri('https://shop.example/en/products');

        static::assertNotNull($germanResolution);
        static::assertNotNull($englishResolution);
        static::assertSame('sales-channel-de', $germanResolution->salesChannelId);
        static::assertSame('sales-channel-en', $englishResolution->salesChannelId);
    }

    public function testItMatchesPunycodeRequestHostAgainstUnicodeDomain(): void
    {
        $resolver = new SalesChannelDomainResolver($this->domainRepository([
            $this->domain('domain-unicode', 'sales-channel-unicode', 'http://würmer.test'),
        ]));

        $resolution = $resolver->resolveByAbsoluteUri('http://xn--wrmer-kva.test/ucp/profile');

        static::assertNotNull($resolution);
        static::assertSame('sales-channel-unicode', $resolution->salesChannelId);
        static::assertSame('domain-unicode', $resolution->domainId);
    }

    public function testItUsesInjectedCacheForRepeatedLookups(): void
    {
        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::once())
            ->method('search')
            ->willReturnCallback(fn (Criteria $criteria, Context $context): EntitySearchResult => $this->searchResult(
                [$this->domain('domain-a', 'sales-channel-a', 'https://shop.example')],
                $criteria,
                $context,
            ));

        $cachedValue = null;
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(static::exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$cachedValue): array {
                if (null !== $cachedValue) {
                    return $cachedValue;
                }

                $item = $this->createMock(ItemInterface::class);
                $item->expects(static::once())
                    ->method('tag')
                    ->with(SalesChannelDomainResolver::CACHE_TAG)
                    ->willReturnSelf();

                $cachedValue = $callback($item);

                return $cachedValue;
            });

        $resolver = new SalesChannelDomainResolver($repository, $cache);

        $firstResolution = $resolver->resolveByAbsoluteUri('https://shop.example/one');
        $secondResolution = $resolver->resolveByAbsoluteUri('https://shop.example/two');

        static::assertNotNull($firstResolution);
        static::assertNotNull($secondResolution);
        static::assertSame('sales-channel-a', $firstResolution->salesChannelId);
        static::assertSame('sales-channel-a', $secondResolution->salesChannelId);
    }

    public function testCacheInvalidatorSubscribesToDomainWriteEvents(): void
    {
        $events = SalesChannelDomainResolverCacheInvalidator::getSubscribedEvents();

        static::assertSame('invalidate', $events['sales_channel_domain.written']);
        static::assertSame('invalidate', $events['sales_channel_domain.deleted']);
    }

    public function testCacheInvalidatorInvalidatesResolverTag(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects(static::once())
            ->method('invalidateTags')
            ->with([SalesChannelDomainResolver::CACHE_TAG])
            ->willReturn(true);

        $invalidator = new SalesChannelDomainResolverCacheInvalidator($cache);
        $invalidator->invalidate($this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent::class));
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     *
     * @return EntityRepository<SalesChannelDomainCollection>
     */
    private function domainRepository(array $domains, ?\Closure $assertCriteria = null): EntityRepository
    {
        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($domains, $assertCriteria): EntitySearchResult {
                $assertCriteria?->__invoke($criteria);

                return $this->searchResult($domains, $criteria, $context);
            });

        return $repository;
    }

    /**
     * @param list<SalesChannelDomainEntity> $domains
     *
     * @return EntitySearchResult<SalesChannelDomainCollection>
     */
    private function searchResult(array $domains, Criteria $criteria, Context $context): EntitySearchResult
    {
        $collection = new SalesChannelDomainCollection($domains);

        return new EntitySearchResult(
            'sales_channel_domain',
            $collection->count(),
            $collection,
            null,
            $criteria,
            $context,
        );
    }

    private function domain(string $id, string $salesChannelId, string $url): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId($id);
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId('language-'.$id);
        $domain->setCurrencyId('currency-'.$id);
        $domain->setUrl($url);

        return $domain;
    }
}
