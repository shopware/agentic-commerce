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

/** @internal */
#[Package('framework')]
final class SalesChannelViewProvider
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addAssociation('domains');

        $channels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $payload = [];

        foreach ($channels as $salesChannel) {
            /** @var SalesChannelEntity $salesChannel */
            $domains = [];
            $domainCollection = $salesChannel->getDomains();
            if (null !== $domainCollection) {
                foreach ($domainCollection as $domain) {
                    /* @var SalesChannelDomainEntity $domain */
                    $domains[] = [
                        'id' => $domain->getId(),
                        'url' => $domain->getUrl(),
                        'languageId' => $domain->getLanguageId(),
                        'currencyId' => $domain->getCurrencyId(),
                    ];
                }
            }

            $payload[] = [
                'id' => $salesChannel->getId(),
                'name' => $salesChannel->getName(),
                'typeId' => $salesChannel->getTypeId(),
                'domains' => $domains,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $salesChannelId, Context $context): ?array
    {
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return null;
        }

        $domains = [];
        $domainCollection = $salesChannel->getDomains();
        if (null !== $domainCollection) {
            foreach ($domainCollection as $domain) {
                /* @var SalesChannelDomainEntity $domain */
                $domains[] = [
                    'id' => $domain->getId(),
                    'url' => $domain->getUrl(),
                    'languageId' => $domain->getLanguageId(),
                    'currencyId' => $domain->getCurrencyId(),
                ];
            }
        }

        return [
            'id' => $salesChannel->getId(),
            'name' => $salesChannel->getName(),
            'typeId' => $salesChannel->getTypeId(),
            'domains' => $domains,
        ];
    }

    public function firstDomainUrl(string $salesChannelId, ?Context $context = null): ?string
    {
        return $this->domainUrls($salesChannelId, $context)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function domainUrls(string $salesChannelId, ?Context $context = null): array
    {
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            return [];
        }

        $urls = [];
        foreach ($salesChannel->getDomains() ?? [] as $domain) {
            $urls[] = $domain->getUrl();
        }

        return $urls;
    }
}
