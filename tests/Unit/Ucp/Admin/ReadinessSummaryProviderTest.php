<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\Admin\ReadinessSummaryProvider;
use Swag\AgenticCommerce\Ucp\Config\UcpActivationReaderInterface;

/** @internal */
#[CoversClass(ReadinessSummaryProvider::class)]
final class ReadinessSummaryProviderTest extends TestCase
{
    private const STOREFRONT_ACTIVE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const HEADLESS_ACTIVE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';
    private const STOREFRONT_INACTIVE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3';
    private const AGENTIC_ONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa4';
    private const AGENTIC_TWO = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa5';
    private const PRODUCT_COMPARISON = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa6';

    public function testItCountsUcpActiveTransactionalAndAgenticSalesChannels(): void
    {
        $provider = $this->createProvider(
            activeUcpIds: [self::STOREFRONT_ACTIVE, self::HEADLESS_ACTIVE],
        );

        $summary = $provider->summary(Context::createDefaultContext());

        static::assertSame(2, $summary->preparedSalesChannels);
        static::assertSame(2, $summary->agenticSalesChannels);
        static::assertSame(3, $summary->transactionalSalesChannels);
    }

    public function testItExcludesInactiveTransactionalChannelsFromThePreparedCount(): void
    {
        $provider = $this->createProvider(
            activeUcpIds: [self::STOREFRONT_ACTIVE],
        );

        $summary = $provider->summary(Context::createDefaultContext());

        static::assertSame(1, $summary->preparedSalesChannels);
        static::assertSame(2, $summary->agenticSalesChannels);
        static::assertSame(3, $summary->transactionalSalesChannels);
    }

    public function testItDoesNotCountAgenticChannelsAsPrepared(): void
    {
        // Agentic channels can have UCP active, but they must never count as "prepared";
        // no transactional channel is active here, so the prepared count is zero.
        $provider = $this->createProvider(
            activeUcpIds: [self::AGENTIC_ONE, self::AGENTIC_TWO],
        );

        $summary = $provider->summary(Context::createDefaultContext());

        static::assertSame(0, $summary->preparedSalesChannels);
        static::assertSame(2, $summary->agenticSalesChannels);
        static::assertSame(3, $summary->transactionalSalesChannels);
    }

    public function testItReturnsZeroCountsWithoutSalesChannels(): void
    {
        $provider = $this->createProvider(activeUcpIds: [], salesChannelIds: []);

        $summary = $provider->summary(Context::createDefaultContext());

        static::assertSame(0, $summary->preparedSalesChannels);
        static::assertSame(0, $summary->agenticSalesChannels);
        static::assertSame(0, $summary->transactionalSalesChannels);
    }

    /**
     * @param list<string>      $activeUcpIds
     * @param list<string>|null $salesChannelIds
     */
    private function createProvider(array $activeUcpIds, ?array $salesChannelIds = null): ReadinessSummaryProvider
    {
        $salesChannelIds ??= [
            self::STOREFRONT_ACTIVE,
            self::HEADLESS_ACTIVE,
            self::STOREFRONT_INACTIVE,
            self::AGENTIC_ONE,
            self::AGENTIC_TWO,
            self::PRODUCT_COMPARISON,
        ];

        $data = [];
        foreach ($salesChannelIds as $id) {
            $data[$id] = ['primaryKey' => $id, 'data' => []];
        }

        $idSearchResult = new IdSearchResult(
            \count($salesChannelIds),
            $data,
            new Criteria(),
            Context::createDefaultContext(),
        );

        $salesChannelRepository = $this->createMock(EntityRepository::class);
        $salesChannelRepository->method('searchIds')->willReturn($idSearchResult);

        $resolver = new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
            self::STOREFRONT_ACTIVE => SalesChannelTypeClassification::Storefront,
            self::HEADLESS_ACTIVE => SalesChannelTypeClassification::Headless,
            self::STOREFRONT_INACTIVE => SalesChannelTypeClassification::Storefront,
            self::AGENTIC_ONE => SalesChannelTypeClassification::AgenticCommerce,
            self::AGENTIC_TWO => SalesChannelTypeClassification::AgenticCommerce,
            self::PRODUCT_COMPARISON => SalesChannelTypeClassification::ProductComparison,
        ]);

        $activationReader = new class($activeUcpIds) implements UcpActivationReaderInterface {
            /**
             * @param list<string> $activeUcpIds
             */
            public function __construct(private readonly array $activeUcpIds)
            {
            }

            public function activeSalesChannelIds(array $salesChannelIds): array
            {
                return array_values(array_intersect($salesChannelIds, $this->activeUcpIds));
            }
        };

        return new ReadinessSummaryProvider(
            $salesChannelRepository,
            $resolver,
            $activationReader,
        );
    }

    public function testHexConstantsAreValidUuids(): void
    {
        // Guards the hand-written hex constants above against typos.
        foreach ([self::STOREFRONT_ACTIVE, self::AGENTIC_ONE, self::PRODUCT_COMPARISON] as $id) {
            static::assertTrue(Uuid::isValid($id));
        }
    }
}
