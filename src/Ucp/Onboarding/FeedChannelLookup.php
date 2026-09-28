<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Finds the Agentic Commerce feed channels that export a given storefront,
 * at most one per provider (the oldest wins).
 *
 * @internal
 */
#[Package('framework')]
final class FeedChannelLookup
{
    /**
     * @param EntityRepository<ProductExportCollection> $productExportRepository
     */
    public function __construct(
        private readonly EntityRepository $productExportRepository,
    ) {
    }

    /**
     * @param list<string> $storefrontIds
     *
     * @return array<string, list<FeedChannelView>> keyed by storefront sales channel id
     */
    public function forStorefronts(array $storefrontIds, Context $context): array
    {
        $storefrontIds = array_values(array_filter($storefrontIds, Uuid::isValid(...)));
        if ([] === $storefrontIds) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('salesChannelDomain');
        $criteria->addFilter(
            new EqualsFilter('salesChannel.typeId', SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE),
            new EqualsAnyFilter('storefrontSalesChannelId', $storefrontIds),
            new EqualsAnyFilter('provider', array_map(static fn (FeedProvider $p): string => $p->value, FeedProvider::cases())),
        );
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

        $byStorefront = [];
        foreach ($this->productExportRepository->search($criteria, $context)->getEntities() as $export) {
            if (!$export instanceof ProductExportEntity) {
                continue;
            }

            $provider = FeedProvider::tryFrom((string) ($export->has('provider') ? $export->get('provider') : ''));
            $storefrontId = $export->getStorefrontSalesChannelId();
            if (null === $provider || isset($byStorefront[$storefrontId][$provider->value])) {
                continue;
            }

            $byStorefront[$storefrontId][$provider->value] = $this->view($export, $provider);
        }

        $result = [];
        foreach ($byStorefront as $storefrontId => $feeds) {
            $ordered = [];
            foreach (FeedProvider::cases() as $provider) {
                if (isset($feeds[$provider->value])) {
                    $ordered[] = $feeds[$provider->value];
                }
            }
            $result[$storefrontId] = $ordered;
        }

        return $result;
    }

    public static function feedUrl(string $domainUrl, string $accessKey, string $fileName): string
    {
        return \sprintf('%s/store-api/product-export/%s/%s', rtrim($domainUrl, '/'), $accessKey, $fileName);
    }

    private function view(ProductExportEntity $export, FeedProvider $provider): FeedChannelView
    {
        $salesChannel = $export->getSalesChannel();
        $domain = $export->getSalesChannelDomain();
        $name = $salesChannel?->getTranslation('name') ?? $salesChannel?->getName();

        return new FeedChannelView(
            $provider,
            $export->getSalesChannelId(),
            \is_string($name) ? $name : null,
            null === $domain ? null : self::feedUrl($domain->getUrl(), $export->getAccessKey(), $export->getFileName()),
            (bool) $salesChannel?->getActive(),
        );
    }
}
