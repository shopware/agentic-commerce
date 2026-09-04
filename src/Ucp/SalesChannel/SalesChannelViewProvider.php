<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;

/** @internal */
#[Package('discovery')]
final class SalesChannelViewProvider
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractSalesChannelTypeResolver $salesChannelTypeResolver,
    ) {
    }

    /**
     * @return list<SalesChannelView>
     */
    public function all(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('domains');

        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $typeClassificationBySalesChannelId = $this->salesChannelTypeResolver->resolveMany(array_values($salesChannels->getIds()));
        $payload = [];

        foreach ($salesChannels as $salesChannel) {
            /** @var SalesChannelEntity $salesChannel */
            $typeClassification = $typeClassificationBySalesChannelId[$salesChannel->getId()] ?? SalesChannelTypeClassification::Other;
            if (!$typeClassification->isTransactional()) {
                continue;
            }

            $payload[] = $this->view($salesChannel, $typeClassification);
        }

        return $payload;
    }

    public function get(string $salesChannelId, Context $context): ?SalesChannelView
    {
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return null;
        }

        return $this->view($salesChannel, $this->salesChannelTypeResolver->resolve($salesChannelId));
    }

    public function firstDomainUrl(string $salesChannelId, ?Context $context = null): ?string
    {
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return null;
        }

        $domains = $salesChannel->getDomains();

        return $domains?->first()?->getUrl();
    }

    private function view(SalesChannelEntity $salesChannel, SalesChannelTypeClassification $typeClassification): SalesChannelView
    {
        $domains = [];
        foreach ($salesChannel->getDomains() ?? [] as $domain) {
            /* @var SalesChannelDomainEntity $domain */
            $domains[] = new SalesChannelDomainView(
                $domain->getId(),
                $domain->getUrl(),
                $domain->getLanguageId(),
                $domain->getCurrencyId(),
            );
        }

        return new SalesChannelView(
            $salesChannel->getId(),
            $salesChannel->getName(),
            $salesChannel->getTypeId(),
            $typeClassification->isTransactional(),
            $domains,
        );
    }
}
