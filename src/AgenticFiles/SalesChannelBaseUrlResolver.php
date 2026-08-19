<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Resolves the absolute base URL of the domain the request came in on, reusing the domains already
 * loaded onto the context when present and otherwise loading just that domain. Shared by the agentic
 * file renderer and the API catalog, so neither has to re-implement the lookup.
 *
 * @internal
 */
#[Package('discovery')]
final class SalesChannelBaseUrlResolver
{
    /**
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        private readonly EntityRepository $domainRepository,
    ) {
    }

    public function resolve(SalesChannelContext $context): ?string
    {
        $domain = $this->resolveFromDomains($context) ?? $this->loadCurrentDomain($context);

        return null !== $domain ? rtrim($domain->getUrl(), '/') : null;
    }

    private function resolveFromDomains(SalesChannelContext $context): ?SalesChannelDomainEntity
    {
        $domainId = $context->getDomainId();
        $domains = $context->getSalesChannel()->getDomains();

        if (null === $domainId || null === $domains || 0 === $domains->count()) {
            return null;
        }

        return $domains->get($domainId);
    }

    private function loadCurrentDomain(SalesChannelContext $context): ?SalesChannelDomainEntity
    {
        $domainId = $context->getDomainId();
        if (null === $domainId) {
            return null;
        }

        $criteria = (new Criteria([$domainId]))->setLimit(1);

        return $this->domainRepository->search($criteria, $context->getContext())->getEntities()->first();
    }
}
