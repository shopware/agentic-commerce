<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\System\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClass;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeResolver;

/** @internal */
#[CoversClass(SalesChannelTypeResolver::class)]
final class SalesChannelTypeResolverTest extends TestCase
{
    public function testItClassifiesEachSalesChannelByItsType(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository([
            'storefront-channel' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
            'feed-channel' => Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON,
        ]));

        static::assertSame(SalesChannelTypeClass::Storefront, $resolver->resolve('storefront-channel'));
        static::assertSame(SalesChannelTypeClass::ProductComparison, $resolver->resolve('feed-channel'));
    }

    public function testAnUnknownSalesChannelIdIsOther(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository([]));

        static::assertSame(SalesChannelTypeClass::Other, $resolver->resolve('does-not-exist'));
    }

    public function testAThirdPartySalesChannelTypeIsOther(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository([
            'social-channel' => '9ce0868f406d47d98cfe4b281e62f098',
        ]));

        static::assertSame(SalesChannelTypeClass::Other, $resolver->resolve('social-channel'));
    }

    public function testItAsksForTheGivenIdsOnly(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository(
            ['storefront-channel' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT],
            static function (Criteria $criteria): void {
                static::assertSame(['storefront-channel', 'feed-channel'], $criteria->getIds());
            },
        ));

        static::assertSame([
            'storefront-channel' => SalesChannelTypeClass::Storefront,
            'feed-channel' => SalesChannelTypeClass::Other,
        ], $resolver->resolveMany(['storefront-channel', 'feed-channel']));
    }

    public function testItReadsEachSalesChannelOnlyOnce(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository(
            ['storefront-channel' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT],
            expectedReads: 1,
        ));

        static::assertSame(SalesChannelTypeClass::Storefront, $resolver->resolve('storefront-channel'));
        static::assertSame(SalesChannelTypeClass::Storefront, $resolver->resolve('storefront-channel'));
        static::assertSame(
            ['storefront-channel' => SalesChannelTypeClass::Storefront],
            $resolver->resolveMany(['storefront-channel', 'storefront-channel']),
        );
    }

    public function testItRemembersThatASalesChannelIsUnknown(): void
    {
        $resolver = new SalesChannelTypeResolver($this->repository([], expectedReads: 1));

        static::assertSame(SalesChannelTypeClass::Other, $resolver->resolve('feed-channel'));
        static::assertSame(SalesChannelTypeClass::Other, $resolver->resolve('feed-channel'));
    }

    public function testItReadsOnlyTheIdsItHasNotSeenYet(): void
    {
        $asked = [];
        $resolver = new SalesChannelTypeResolver($this->repository(
            [
                'storefront-a' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
                'storefront-b' => Defaults::SALES_CHANNEL_TYPE_API,
            ],
            static function (Criteria $criteria) use (&$asked): void {
                $asked[] = $criteria->getIds();
            },
            expectedReads: 2,
        ));

        $resolver->resolveMany(['storefront-a', 'feed-a']);
        $resolver->resolveMany(['storefront-a', 'feed-a', 'storefront-b', 'feed-b']);

        static::assertSame([['storefront-a', 'feed-a'], ['storefront-b', 'feed-b']], $asked);
    }

    public function testItReadsNothingForAnEmptyIdList(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('search');

        static::assertSame([], (new SalesChannelTypeResolver($repository))->resolveMany([]));
    }

    public function testTheCoreImplementationCannotBeDecorated(): void
    {
        $this->expectException(DecorationPatternException::class);

        (new SalesChannelTypeResolver($this->createMock(EntityRepository::class)))->getDecorated();
    }

    /**
     * @param array<string, string>           $typeIdsBySalesChannelId
     * @param (callable(Criteria): void)|null $assertCriteria
     *
     * @return EntityRepository<SalesChannelCollection>
     */
    private function repository(array $typeIdsBySalesChannelId, ?callable $assertCriteria = null, ?int $expectedReads = null): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(null === $expectedReads ? static::atLeastOnce() : static::exactly($expectedReads))
            ->method('search')
            ->willReturnCallback(
                static function (Criteria $criteria, Context $context) use ($typeIdsBySalesChannelId, $assertCriteria): EntitySearchResult {
                    if (null !== $assertCriteria) {
                        $assertCriteria($criteria);
                    }

                    $salesChannels = [];
                    foreach ($criteria->getIds() as $salesChannelId) {
                        if (!\is_string($salesChannelId) || !isset($typeIdsBySalesChannelId[$salesChannelId])) {
                            continue;
                        }

                        $salesChannel = new SalesChannelEntity();
                        $salesChannel->setId($salesChannelId);
                        $salesChannel->setUniqueIdentifier($salesChannelId);
                        $salesChannel->setTypeId($typeIdsBySalesChannelId[$salesChannelId]);
                        $salesChannels[] = $salesChannel;
                    }

                    return new EntitySearchResult(
                        'sales_channel',
                        \count($salesChannels),
                        new SalesChannelCollection($salesChannels),
                        null,
                        $criteria,
                        $context,
                    );
                },
            );

        return $repository;
    }
}
