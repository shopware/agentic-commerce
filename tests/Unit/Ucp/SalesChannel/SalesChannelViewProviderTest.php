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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/** @internal */
#[CoversClass(SalesChannelViewProvider::class)]
final class SalesChannelViewProviderTest extends TestCase
{
    public function testItOffersOnlySalesChannelsUcpCanRunOn(): void
    {
        $repository = $this->salesChannelRepository(static function (Criteria $criteria): void {
            $typeFilters = array_values(array_filter(
                $criteria->getFilters(),
                static fn (object $filter): bool => $filter instanceof EqualsAnyFilter && 'typeId' === $filter->getField(),
            ));

            static::assertCount(1, $typeFilters);
            static::assertSame(
                [Defaults::SALES_CHANNEL_TYPE_STOREFRONT, Defaults::SALES_CHANNEL_TYPE_API],
                $typeFilters[0]->getValue(),
            );
            static::assertArrayHasKey('domains', $criteria->getAssociations());
        });

        $provider = new SalesChannelViewProvider($repository);
        $salesChannels = $provider->all(Context::createDefaultContext());

        static::assertSame(['storefront-channel'], array_column($salesChannels, 'id'));
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
        ));

        $salesChannel = $provider->get('storefront-channel', Context::createDefaultContext());

        static::assertNotNull($salesChannel);
        static::assertSame('storefront-channel', $salesChannel['id']);
        static::assertSame(Defaults::SALES_CHANNEL_TYPE_STOREFRONT, $salesChannel['typeId']);
        static::assertSame([[
            'id' => 'domain-id',
            'url' => 'https://shop.example',
            'languageId' => 'language-id',
            'currencyId' => 'currency-id',
        ]], $salesChannel['domains']);
    }

    public function testItReturnsNullForAnUnknownSalesChannel(): void
    {
        $provider = new SalesChannelViewProvider($this->emptySalesChannelRepository());

        static::assertNull($provider->get('does-not-exist', Context::createDefaultContext()));
    }

    public function testItResolvesTheFirstDomainUrl(): void
    {
        $provider = new SalesChannelViewProvider($this->salesChannelRepository(
            static function (Criteria $criteria): void {
                static::assertSame(['storefront-channel'], $criteria->getIds());
            },
            withDomain: true,
        ));

        static::assertSame('https://shop.example', $provider->firstDomainUrl('storefront-channel'));
    }

    public function testItHasNoFirstDomainUrlWithoutADomain(): void
    {
        $provider = new SalesChannelViewProvider($this->salesChannelRepository(static function (): void {}));

        static::assertNull($provider->firstDomainUrl('storefront-channel'));
    }

    public function testItHasNoFirstDomainUrlForAnUnknownSalesChannel(): void
    {
        $provider = new SalesChannelViewProvider($this->emptySalesChannelRepository());

        static::assertNull($provider->firstDomainUrl('does-not-exist'));
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
     * @param callable(Criteria): void $assertCriteria
     *
     * @return EntityRepository<SalesChannelCollection>
     */
    private function salesChannelRepository(callable $assertCriteria, bool $withDomain = false): EntityRepository
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('storefront-channel');
        $salesChannel->setUniqueIdentifier('storefront-channel');
        $salesChannel->setName('Storefront');
        $salesChannel->setTypeId(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);

        if ($withDomain) {
            $domain = new SalesChannelDomainEntity();
            $domain->setId('domain-id');
            $domain->setUniqueIdentifier('domain-id');
            $domain->setUrl('https://shop.example');
            $domain->setLanguageId('language-id');
            $domain->setCurrencyId('currency-id');
            $salesChannel->setDomains(new SalesChannelDomainCollection([$domain]));
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(static::once())
            ->method('search')
            ->willReturnCallback(
                static function (Criteria $criteria, Context $context) use ($assertCriteria, $salesChannel): EntitySearchResult {
                    $assertCriteria($criteria);

                    return new EntitySearchResult(
                        'sales_channel',
                        1,
                        new SalesChannelCollection([$salesChannel]),
                        null,
                        $criteria,
                        $context,
                    );
                },
            );

        return $repository;
    }
}
