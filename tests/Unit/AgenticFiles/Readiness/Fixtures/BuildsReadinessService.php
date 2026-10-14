<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessService;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/**
 * A shop with a storefront, a headless and a product comparison sales channel.
 *
 * @internal
 */
trait BuildsReadinessService
{
    /**
     * @param ?array<string, array{string, SalesChannelTypeClassification}> $salesChannels name and type per id
     */
    private function readinessService(InMemoryAgenticFilesCoreBridge $bridge, ?array $salesChannels = null): BasicAiReadinessService
    {
        return new BasicAiReadinessService($bridge, $this->salesChannelViewProvider($salesChannels));
    }

    /**
     * @param ?array<string, array{string, SalesChannelTypeClassification}> $salesChannels name and type per id
     */
    private function salesChannelViewProvider(?array $salesChannels = null): SalesChannelViewProvider
    {
        $salesChannels ??= [
            ReadinessShop::STOREFRONT_ID => ['Storefront', SalesChannelTypeClassification::Storefront],
            ReadinessShop::HEADLESS_ID => ['Headless', SalesChannelTypeClassification::Headless],
            ReadinessShop::FEED_ID => ['Feed', SalesChannelTypeClassification::ProductComparison],
        ];

        $entities = [];
        $classification = [];
        foreach ($salesChannels as $id => [$name, $type]) {
            $salesChannel = new SalesChannelEntity();
            $salesChannel->setId($id);
            $salesChannel->setUniqueIdentifier($id);
            $salesChannel->setName($name);
            $salesChannel->setTypeId('0191dddddddd7000dddddddddddddddd');
            $entities[] = $salesChannel;
            $classification[$id] = $type;
        }

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection($entities));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        return new SalesChannelViewProvider($repository, new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, $classification));
    }
}
