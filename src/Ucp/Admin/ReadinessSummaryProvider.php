<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Ucp\Config\UcpActivationReaderInterface;

/**
 * Computes the two readiness counts shown on the core Agentic Commerce settings
 * page: how many transactional (Storefront/Headless) sales channels have UCP
 * enabled, and how many agentic-commerce sales channels exist.
 *
 * Enabling UCP for a channel also enables its AI files (the plugin does both in
 * one step), so UCP being active is the signal that a channel is prepared.
 *
 * @internal
 */
#[Package('framework')]
final class ReadinessSummaryProvider
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractSalesChannelTypeResolver $salesChannelTypeResolver,
        private readonly UcpActivationReaderInterface $ucpActivationReader,
    ) {
    }

    public function summary(Context $context): ReadinessSummary
    {
        $salesChannelIds = array_filter($this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds(), 'is_string');

        if ([] === $salesChannelIds) {
            return new ReadinessSummary(0, 0, 0);
        }

        $classifications = $this->salesChannelTypeResolver->resolveMany(array_values($salesChannelIds));

        $agenticSalesChannels = \count(array_filter(
            $classifications,
            static fn (SalesChannelTypeClassification $classification): bool => SalesChannelTypeClassification::AgenticCommerce === $classification,
        ));

        $transactionalIds = array_keys(array_filter(
            $classifications,
            static fn (SalesChannelTypeClassification $classification): bool => $classification->isTransactional(),
        ));

        $preparedSalesChannels = [] === $transactionalIds
            ? 0
            : \count($this->ucpActivationReader->activeSalesChannelIds($transactionalIds));

        return new ReadinessSummary($preparedSalesChannels, $agenticSalesChannels, \count($transactionalIds));
    }
}
