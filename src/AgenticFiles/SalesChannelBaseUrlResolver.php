<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Resolves the absolute base URL of the current sales-channel domain, preferring the domain the
 * request came in on and falling back to the sales channel's first domain. Shared by the agentic
 * file renderer and the API catalog, so neither has to re-implement the domain-pick logic.
 *
 * @internal
 */
#[Package('discovery')]
final class SalesChannelBaseUrlResolver
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    public function resolve(SalesChannelContext $context): ?string
    {
        return $this->resolveFromSalesChannel($this->loadSalesChannel($context), $context);
    }

    public function resolveFromSalesChannel(?SalesChannelEntity $salesChannel, SalesChannelContext $context): ?string
    {
        $domains = $salesChannel?->getDomains();
        if (null === $domains || 0 === $domains->count()) {
            return null;
        }

        $domainId = $context->getDomainId();
        if (null !== $domainId) {
            $domain = $domains->get($domainId);
            if ($domain instanceof SalesChannelDomainEntity) {
                return rtrim($domain->getUrl(), '/');
            }
        }

        $domain = $domains->first();

        return $domain instanceof SalesChannelDomainEntity ? rtrim($domain->getUrl(), '/') : null;
    }

    private function loadSalesChannel(SalesChannelContext $context): ?SalesChannelEntity
    {
        $criteria = (new Criteria([$context->getSalesChannelId()]))
            ->addAssociation('domains')
            ->setLimit(1);

        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $context->getContext())
            ->getEntities()
            ->first();

        return $salesChannel instanceof SalesChannelEntity ? $salesChannel : null;
    }
}
