<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;

final readonly class SalesChannelDomainResolver
{
    /**
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        private EntityRepository $domainRepository,
    ) {
    }

    public function resolveByAbsoluteUri(string $absoluteUri, ?Context $context = null): ?SalesChannelResolution
    {
        $host = parse_url($absoluteUri, \PHP_URL_HOST);
        $path = (string) (parse_url($absoluteUri, \PHP_URL_PATH) ?? '/');

        if (!\is_string($host) || '' === $host) {
            return null;
        }

        return $this->resolveByHostAndPath($host, $path, $context);
    }

    public function resolveByBaseUri(string $baseUri, ?Context $context = null): ?SalesChannelResolution
    {
        return $this->resolveByAbsoluteUri($baseUri, $context);
    }

    public function resolveByHostAndPath(string $host, string $path = '/', ?Context $context = null): ?SalesChannelResolution
    {
        $bestMatch = null;
        $bestPathLength = -1;

        foreach ($this->loadDomains($context ?? Context::createDefaultContext()) as $domain) {
            $domainHost = parse_url($domain->getUrl(), \PHP_URL_HOST);
            if (!\is_string($domainHost) || !hash_equals(strtolower($domainHost), strtolower($host))) {
                continue;
            }

            $domainPath = rtrim((string) (parse_url($domain->getUrl(), \PHP_URL_PATH) ?? ''), '/');
            if ('' !== $domainPath && !str_starts_with($path, $domainPath)) {
                continue;
            }

            if (\strlen($domainPath) <= $bestPathLength) {
                continue;
            }

            $bestMatch = new SalesChannelResolution(
                $domain->getSalesChannelId(),
                rtrim($domain->getUrl(), '/'),
                $domain->getId(),
                $domain->getLanguageId(),
                $domain->getCurrencyId(),
            );
            $bestPathLength = \strlen($domainPath);
        }

        return $bestMatch;
    }

    /**
     * @return list<SalesChannelDomainEntity>
     */
    private function loadDomains(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->setLimit(500);

        return array_values($this->domainRepository->search($criteria, $context)->getEntities()->getElements());
    }
}
