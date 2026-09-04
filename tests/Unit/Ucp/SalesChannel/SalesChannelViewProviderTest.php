<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/** @internal */
#[CoversClass(SalesChannelViewProvider::class)]
final class SalesChannelViewProviderTest extends TestCase
{
    private const PARTNER_TYPE_ID = '9ce0868f406d47d98cfe4b281e62f098';

    public function testItOffersTheSalesChannelsTheResolverClassifiesAsTransactional(): void
    {
        $repository = $this->salesChannelRepository(
            static function (Criteria $criteria): void {
                static::assertSame([], $criteria->getFilters());
                static::assertArrayHasKey('domains', $criteria->getAssociations());
            },
            channels: [
                ['storefront-channel', Defaults::SALES_CHANNEL_TYPE_STOREFRONT],
                ['feed-channel', Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON],
                ['partner-channel', self::PARTNER_TYPE_ID],
            ],
        );

        $provider = new SalesChannelViewProvider($repository, new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
            'storefront-channel' => SalesChannelTypeClassification::Storefront,
            'feed-channel' => SalesChannelTypeClassification::ProductComparison,
            'partner-channel' => SalesChannelTypeClassification::Storefront,
        ]));

        $salesChannels = $provider->all(Context::createDefaultContext());

        static::assertSame(
            ['storefront-channel', 'partner-channel'],
            array_map(static fn (SalesChannelView $salesChannel): string => $salesChannel->id, $salesChannels),
        );
        static::assertContainsOnlyInstancesOf(SalesChannelView::class, $salesChannels);
        foreach ($salesChannels as $salesChannel) {
            static::assertTrue($salesChannel->transactional);
        }
    }

    public function testItReturnsASingleSalesChannelWithItsDomains(): void
    {
        $provider = new SalesChannelViewProvider($this->salesChannelRepository(
            static function (Criteria $criteria): void {
                static::assertSame(['storefront-channel'], $criteria->getIds());
                static::assertArrayHasKey('domains', $criteria->getAssociations());
                static::assertSame(1, $criteria->getLimit());
            },
            withDomain: true,
        ), $this->resolver());

        $salesChannel = $provider->get('storefront-channel', Context::createDefaultContext());

        static::assertNotNull($salesChannel);
        static::assertSame([
            'id' => 'storefront-channel',
            'name' => 'Storefront-channel',
            'typeId' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
            'transactional' => true,
            'domains' => [[
                'id' => 'domain-id',
                'url' => 'https://shop.example',
                'languageId' => 'language-id',
                'currencyId' => 'currency-id',
            ]],
        ], $salesChannel->jsonSerialize());
    }

    public function testItReportsASingleSalesChannelTheResolverClassifiesAsFeed(): void
    {
        $provider = new SalesChannelViewProvider(
            $this->salesChannelRepository(static function (): void {}),
            $this->resolver(SalesChannelTypeClassification::ProductComparison),
        );

        $salesChannel = $provider->get('storefront-channel', Context::createDefaultContext());

        static::assertNotNull($salesChannel);
        static::assertFalse($salesChannel->transactional);
    }

    public function testItReturnsNullForAnUnknownSalesChannel(): void
    {
        $provider = new SalesChannelViewProvider($this->emptySalesChannelRepository(), $this->resolver());

        static::assertNull($provider->get('does-not-exist', Context::createDefaultContext()));
    }

    public function testItResolvesTheFirstDomainUrl(): void
    {
        $provider = new SalesChannelViewProvider($this->salesChannelRepository(
            static function (Criteria $criteria): void {
                static::assertSame(['storefront-channel'], $criteria->getIds());
            },
            withDomain: true,
        ), $this->resolver());

        static::assertSame('https://shop.example', $provider->firstDomainUrl('storefront-channel'));
    }

    public function testItHasNoFirstDomainUrlWithoutADomain(): void
    {
        $provider = new SalesChannelViewProvider($this->salesChannelRepository(static function (): void {}), $this->resolver());

        static::assertNull($provider->firstDomainUrl('storefront-channel'));
    }

    public function testItHasNoFirstDomainUrlForAnUnknownSalesChannel(): void
    {
        $provider = new SalesChannelViewProvider($this->emptySalesChannelRepository(), $this->resolver());

        static::assertNull($provider->firstDomainUrl('does-not-exist'));
    }

    private function resolver(SalesChannelTypeClassification $class = SalesChannelTypeClassification::Storefront): StaticSalesChannelTypeResolver
    {
        return new StaticSalesChannelTypeResolver($class);
    }

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
    private function emptySalesChannelRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'sales_channel',
                    0,
                    new SalesChannelCollection(),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }

    /**
     * @param callable(Criteria): void          $assertCriteria
     * @param list<array{0: string, 1: string}> $channels       id and type id per sales channel
     *
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(
        callable $assertCriteria,
        bool $withDomain = false,
        array $channels = [['storefront-channel', Defaults::SALES_CHANNEL_TYPE_STOREFRONT]],
    ): EntityRepository {
        $salesChannels = [];
        foreach ($channels as [$id, $typeId]) {
            $salesChannel = new SalesChannelEntity();
            $salesChannel->setId($id);
            $salesChannel->setUniqueIdentifier($id);
            $salesChannel->setName(ucfirst($id));
            $salesChannel->setTypeId($typeId);

            if ($withDomain) {
                $domain = new SalesChannelDomainEntity();
                $domain->setId('domain-id');
                $domain->setUniqueIdentifier('domain-id');
                $domain->setUrl('https://shop.example');
                $domain->setLanguageId('language-id');
                $domain->setCurrencyId('currency-id');
                $salesChannel->setDomains(new SalesChannelDomainCollection([$domain]));
            }

            $salesChannels[] = $salesChannel;
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(static::once())
            ->method('search')
            ->willReturnCallback(
                static function (Criteria $criteria, Context $context) use ($assertCriteria, $salesChannels): EntitySearchResult {
                    $assertCriteria($criteria);

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
