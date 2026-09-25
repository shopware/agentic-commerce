<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Content\ProductStream\ProductStreamCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Util\AccessKeyHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Creates one Agentic Commerce feed channel per requested (storefront, provider),
 * defaulting every required field from the storefront the way the Admin's
 * sales-channel detail page does when a storefront is picked for a product export.
 *
 * @internal
 */
#[Package('framework')]
final class AgenticFeedChannelCreator
{
    public const DEFAULT_PRODUCT_STREAM_NAME = 'Agentic Commerce – all active products';

    private const FEED_INTERVAL_SECONDS = 86400;

    /**
     * @param EntityRepository<SalesChannelCollection>  $salesChannelRepository
     * @param EntityRepository<ProductStreamCollection> $productStreamRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $productStreamRepository,
        private readonly FeedChannelLookup $feedChannelLookup,
        private readonly FeedTemplateLoader $templateLoader,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @param list<array{storefrontSalesChannelId: string, provider: FeedProvider}> $requests
     *
     * @return list<FeedChannelOutcome>
     */
    public function create(array $requests, Context $context): array
    {
        if ([] === $requests) {
            return [];
        }

        $storefrontIds = array_values(array_unique(array_column($requests, 'storefrontSalesChannelId')));
        $storefronts = $this->storefronts($storefrontIds, $context);
        $existing = $this->feedChannelLookup->forStorefronts($storefrontIds, $context);
        $streamEnsured = false;

        $outcomes = [];
        foreach ($requests as ['storefrontSalesChannelId' => $storefrontId, 'provider' => $provider]) {
            $storefront = $storefronts->get($storefrontId);
            $storefrontName = $storefront instanceof SalesChannelEntity ? $this->name($storefront) : null;

            $existingFeed = $this->existingFeed($existing[$storefrontId] ?? [], $provider);
            if (null !== $existingFeed) {
                $outcomes[] = FeedChannelOutcome::skipped($storefrontId, $storefrontName, $existingFeed);

                continue;
            }

            $outcome = $this->createOne($storefrontId, $storefront, $provider, $streamEnsured, $context);
            if ($outcome->isCreated() && null !== $outcome->salesChannelId) {
                $existing[$storefrontId][] = new FeedChannelView($provider, $outcome->salesChannelId, $outcome->salesChannelName, $outcome->feedUrl, true);
            }
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    private function createOne(
        string $storefrontId,
        ?SalesChannelEntity $storefront,
        FeedProvider $provider,
        bool &$streamEnsured,
        Context $context,
    ): FeedChannelOutcome {
        if (null === $storefront) {
            return FeedChannelOutcome::failed($storefrontId, null, $provider, FeedChannelOutcome::CODE_NOT_FOUND, \sprintf('Sales channel "%s" does not exist.', $storefrontId));
        }

        $storefrontName = $this->name($storefront);
        $label = $storefrontName ?? $storefrontId;

        if (Defaults::SALES_CHANNEL_TYPE_STOREFRONT !== $storefront->getTypeId()) {
            return FeedChannelOutcome::failed($storefrontId, $storefrontName, $provider, FeedChannelOutcome::CODE_NOT_A_STOREFRONT, \sprintf('Sales channel "%s" is not a storefront, a feed exports a storefront.', $label));
        }

        $domain = $this->domain($storefront);
        if (null === $domain) {
            return FeedChannelOutcome::failed($storefrontId, $storefrontName, $provider, FeedChannelOutcome::CODE_NO_STOREFRONT_DOMAIN, \sprintf('Sales channel "%s" has no domain, a feed needs a domain URL.', $label));
        }

        $salesChannelId = Uuid::randomHex();
        $salesChannelName = \sprintf('%s – %s', $label, $provider->channelNameSuffix());
        $fileName = \sprintf('agentic-commerce-%s-%s.%s', $provider->value, substr(Uuid::randomHex(), -8), $provider->fileExtension());
        $exportAccessKey = AccessKeyHelper::generateAccessKey('product-export');

        try {
            if (!$streamEnsured) {
                $this->ensureDefaultProductStream($context);
                $streamEnsured = true;
            }

            $this->salesChannelRepository->create([
                $this->salesChannelPayload($salesChannelId, $salesChannelName, $storefront, [
                    'id' => Uuid::randomHex(),
                    'productStreamId' => SwagAgenticCommerce::DEFAULT_PRODUCT_STREAM_ID,
                    'storefrontSalesChannelId' => $storefrontId,
                    'salesChannelDomainId' => $domain->getId(),
                    'currencyId' => $storefront->getCurrencyId(),
                    'fileName' => $fileName,
                    'accessKey' => $exportAccessKey,
                    'encoding' => 'UTF-8',
                    'fileFormat' => $provider->fileFormat(),
                    'generateByCronjob' => true,
                    'interval' => self::FEED_INTERVAL_SECONDS,
                    'includeVariants' => true,
                    'provider' => $provider->value,
                    ...$this->templateLoader->load($provider),
                ]),
            ], $context);

            if (FeedProvider::OpenAi === $provider) {
                $this->systemConfigService->set($provider->configDomain().'.returnPolicyUrl', $domain->getUrl(), $salesChannelId);
            }
        } catch (\Throwable $exception) {
            return FeedChannelOutcome::failed($storefrontId, $storefrontName, $provider, FeedChannelOutcome::CODE_WRITE_FAILED, $exception->getMessage());
        }

        return FeedChannelOutcome::created(
            $storefrontId,
            $storefrontName,
            $provider,
            $salesChannelId,
            $salesChannelName,
            FeedChannelLookup::feedUrl($domain->getUrl(), $exportAccessKey, $fileName),
        );
    }

    /**
     * @param array<string, mixed> $productExport
     *
     * @return array<string, mixed>
     */
    private function salesChannelPayload(string $id, string $name, SalesChannelEntity $storefront, array $productExport): array
    {
        $translations = [Defaults::LANGUAGE_SYSTEM => ['name' => $name]];
        $translations[$storefront->getLanguageId()] = ['name' => $name];

        $payload = [
            'id' => $id,
            'typeId' => SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE,
            'active' => true,
            'accessKey' => AccessKeyHelper::generateAccessKey('sales-channel'),
            'languageId' => $storefront->getLanguageId(),
            'currencyId' => $storefront->getCurrencyId(),
            'paymentMethodId' => $storefront->getPaymentMethodId(),
            'shippingMethodId' => $storefront->getShippingMethodId(),
            'countryId' => $storefront->getCountryId(),
            'customerGroupId' => $storefront->getCustomerGroupId(),
            'navigationCategoryId' => $storefront->getNavigationCategoryId(),
            'navigationCategoryVersionId' => $storefront->getNavigationCategoryVersionId(),
            'translations' => $translations,
            'languages' => $this->idList($storefront->getLanguages(), $storefront->getLanguageId()),
            'currencies' => $this->idList($storefront->getCurrencies(), $storefront->getCurrencyId()),
            'countries' => $this->idList($storefront->getCountries(), $storefront->getCountryId()),
            'paymentMethods' => $this->idList($storefront->getPaymentMethods(), $storefront->getPaymentMethodId()),
            'shippingMethods' => $this->idList($storefront->getShippingMethods(), $storefront->getShippingMethodId()),
            'productExports' => [$productExport],
        ];

        $optional = [
            'footerCategoryId' => $storefront->getFooterCategoryId(),
            'footerCategoryVersionId' => $storefront->getFooterCategoryVersionId(),
            'serviceCategoryId' => $storefront->getServiceCategoryId(),
            'serviceCategoryVersionId' => $storefront->getServiceCategoryVersionId(),
            'homeCmsPageId' => $storefront->getHomeCmsPageId(),
            'homeCmsPageVersionId' => $storefront->getHomeCmsPageVersionId(),
        ];
        foreach ($optional as $key => $value) {
            if (null !== $value) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * The singular default must be part of the to-many list, or the write is rejected.
     *
     * @param EntityCollection<Entity>|null $collection
     *
     * @return list<array{id: string}>
     */
    private function idList(?EntityCollection $collection, string $defaultId): array
    {
        $ids = null === $collection ? [] : $collection->getIds();
        $ids[$defaultId] = $defaultId;

        return array_map(static fn (string $id): array => ['id' => $id], array_values($ids));
    }

    private function ensureDefaultProductStream(Context $context): void
    {
        $id = SwagAgenticCommerce::DEFAULT_PRODUCT_STREAM_ID;
        if (null !== $this->productStreamRepository->searchIds(new Criteria([$id]), $context)->firstId()) {
            return;
        }

        $this->productStreamRepository->create([[
            'id' => $id,
            'name' => self::DEFAULT_PRODUCT_STREAM_NAME,
            'filters' => [['type' => 'equals', 'field' => 'active', 'value' => '1']],
        ]], $context);
    }

    /**
     * @param list<string> $ids
     */
    private function storefronts(array $ids, Context $context): SalesChannelCollection
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));
        if ([] === $ids) {
            return new SalesChannelCollection();
        }

        $criteria = new Criteria($ids);
        foreach (['domains', 'languages', 'currencies', 'countries', 'paymentMethods', 'shippingMethods'] as $association) {
            $criteria->addAssociation($association);
        }

        return $this->salesChannelRepository->search($criteria, $context)->getEntities();
    }

    private function domain(SalesChannelEntity $storefront): ?SalesChannelDomainEntity
    {
        $domains = $storefront->getDomains();
        if (null === $domains || 0 === $domains->count()) {
            return null;
        }

        foreach ($domains as $domain) {
            if ($domain->getLanguageId() === $storefront->getLanguageId()) {
                return $domain;
            }
        }

        return $domains->first();
    }

    /**
     * @param list<FeedChannelView> $feeds
     */
    private function existingFeed(array $feeds, FeedProvider $provider): ?FeedChannelView
    {
        foreach ($feeds as $feed) {
            if ($feed->provider === $provider) {
                return $feed;
            }
        }

        return null;
    }

    private function name(SalesChannelEntity $salesChannel): ?string
    {
        $name = $salesChannel->getTranslation('name') ?? $salesChannel->getName();

        return \is_string($name) ? $name : null;
    }
}
