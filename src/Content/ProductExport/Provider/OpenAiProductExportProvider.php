<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Provider;

use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\SwagAgenticCommerce;

class OpenAiProductExportProvider extends AbstractAgenticCommerceProductExportProvider
{
    /**
     * @internal
     *
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function getTechnicalName(): string
    {
        return 'open-ai';
    }

    protected function buildProviderContext(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
    ): array {
        $storeCountry = $salesChannelContext->getShippingLocation()->getCountry()->getIso();
        $targetCountries = $this->resolveTargetCountries($salesChannelContext);
        $sellerUrl = $productExport->getSalesChannelDomain()->getUrl();

        $config = $this->getSystemConfigValues($productExport);
        $returnPolicyUrl = $this->normalizeStringValue($config, 'returnPolicyUrl', $sellerUrl);

        return [
            'storeCountry' => $storeCountry,
            'targetCountries' => $targetCountries,
            'sellerName' => $salesChannelContext->getSalesChannel()->getName() ?? '',
            'sellerUrl' => $sellerUrl,
            'returnPolicyUrl' => $returnPolicyUrl,
            'isEligibleSearch' => true,
            'isEligibleCheckout' => false,
            'variantMapping' => $this->getVariantMapping($config),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array<string, list<string>|null>
     */
    private function getVariantMapping(array $mapping): array
    {
        return [
            'color' => $this->normalizeMappingValue($mapping, 'variantColor'),
            'size' => $this->normalizeMappingValue($mapping, 'variantSize'),
            'size_system' => $this->normalizeMappingValue($mapping, 'variantSizeSystem'),
            'gender' => $this->normalizeMappingValue($mapping, 'variantGender'),
            'material' => $this->normalizeMappingValue($mapping, 'variantMaterial'),
            'custom_variants' => $this->normalizeMappingValue($mapping, 'variantCustom'),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return list<string>|null
     */
    private function normalizeMappingValue(array $mapping, string $key): ?array
    {
        $value = $mapping[$key] ?? null;

        if (!\is_array($value) || [] === $value) {
            return null;
        }

        $normalized = array_values(array_filter(
            $value,
            static fn (mixed $entry): bool => \is_string($entry) && '' !== trim($entry),
        ));

        return [] !== $normalized ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function normalizeStringValue(array $mapping, string $key, ?string $fallback = null): ?string
    {
        $value = $mapping[$key] ?? null;

        if (!\is_string($value)) {
            return $fallback;
        }

        $value = trim($value);

        return '' === $value ? $fallback : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSystemConfigValues(ProductExportEntity $productExport): array
    {
        $configDomain = SwagAgenticCommerce::OPEN_AI_PRODUCT_EXPORT_CONFIG_DOMAIN;

        $rawMapping = $this->systemConfigService->getDomain(
            $configDomain,
            $productExport->getSalesChannelId(),
            true
        );

        $prefix = $configDomain.'.';

        return array_combine(
            array_map(
                static fn (string $key): string => str_replace($prefix, '', $key),
                array_keys($rawMapping)
            ),
            array_values($rawMapping)
        );
    }

    /**
     * @return list<string>|null
     */
    private function resolveTargetCountries(SalesChannelContext $salesChannelContext): ?array
    {
        $countries = $salesChannelContext->getSalesChannel()->getCountries();
        $targetCountries = $this->extractCountryIsoCodes($countries);

        if ([] !== $targetCountries) {
            return $targetCountries;
        }

        $criteria = (new Criteria([$salesChannelContext->getSalesChannelId()]))
            ->addAssociation('countries');

        $salesChannel = $this->salesChannelRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (null === $salesChannel) {
            return null;
        }

        /** @var SalesChannelEntity $salesChannel */
        $targetCountries = $this->extractCountryIsoCodes($salesChannel->getCountries());

        return [] !== $targetCountries ? $targetCountries : null;
    }

    /**
     * @param CountryCollection<CountryEntity>|null $countries
     *
     * @return list<string>
     */
    private function extractCountryIsoCodes(?iterable $countries): array
    {
        if (null === $countries) {
            return [];
        }

        $isoCodes = [];
        foreach ($countries as $country) {
            $iso = $country->getIso();

            if (null === $iso || '' === $iso) {
                continue;
            }

            $isoCodes[] = $iso;
        }

        return $isoCodes;
    }
}
