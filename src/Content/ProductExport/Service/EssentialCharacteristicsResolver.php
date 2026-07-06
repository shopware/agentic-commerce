<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Service;

use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Resolves a product's essential characteristics (core {@see ProductFeatureSetEntity})
 * into flat, feed-friendly `{section, name, value}` triples.
 *
 * Mirrors the resolution semantics of
 * {@see \Shopware\Core\Content\Product\Cart\ProductFeatureBuilder}, which is not
 * reusable here because its resolver methods are private and it operates on cart
 * line items. Custom-field definitions are cached per instance so an export run
 * queries each definition at most once instead of once per row.
 *
 * @internal
 */
class EssentialCharacteristicsResolver
{
    /**
     * @var array<string, CustomFieldEntity|null>
     */
    private array $customFieldCache = [];

    private ?string $systemLocaleCode = null;

    /**
     * @param EntityRepository<CustomFieldCollection> $customFieldRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldRepository,
        private readonly LanguageLocaleCodeProvider $languageLocaleProvider,
    ) {
    }

    /**
     * @return list<array{section: string, name: string, value: string}>
     */
    public function resolve(SalesChannelProductEntity $product, SalesChannelContext $context): array
    {
        $featureSet = $product->getFeatureSet();
        $features = $featureSet?->getFeatures();

        if (null === $features || [] === $features) {
            return [];
        }

        usort($features, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $section = $this->resolveSection($featureSet);
        $characteristics = [];

        foreach ($features as $feature) {
            $resolved = match ($feature['type']) {
                ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE => $this->resolveAttribute($feature, $product),
                ProductFeatureSetDefinition::TYPE_PRODUCT_PROPERTY => $this->resolveProperty($feature, $product),
                ProductFeatureSetDefinition::TYPE_PRODUCT_CUSTOM_FIELD => $this->resolveCustomField($feature, $product, $context),
                ProductFeatureSetDefinition::TYPE_PRODUCT_REFERENCE_PRICE => $this->resolveReferencePrice($product, $context),
                default => null,
            };

            if (null === $resolved) {
                continue;
            }

            [$name, $value] = $resolved;
            $value = $this->stringify($value);

            if ('' === $name || '' === $value) {
                continue;
            }

            $characteristics[] = ['section' => $section, 'name' => $name, 'value' => $value];
        }

        return $characteristics;
    }

    /**
     * @param array<string, mixed> $feature
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function resolveAttribute(array $feature, SalesChannelProductEntity $product): ?array
    {
        $name = $feature['name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        $translated = $product->getTranslated();

        if (\array_key_exists($name, $translated)) {
            return [$name, $translated[$name]];
        }

        return [$name, $product->has($name) ? $product->get($name) : null];
    }

    /**
     * @param array<string, mixed> $feature
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function resolveProperty(array $feature, SalesChannelProductEntity $product): ?array
    {
        $groupId = $feature['id'] ?? null;
        $properties = $product->getProperties();

        if (!\is_string($groupId) || '' === $groupId || null === $properties) {
            return null;
        }

        $group = $properties->getGroups()->get($groupId);

        if (null === $group) {
            return null;
        }

        $label = $group->getTranslation('name');

        if (!\is_string($label) || '' === $label) {
            return null;
        }

        $optionNames = [];
        foreach ($properties as $option) {
            if ($option->getGroupId() !== $groupId) {
                continue;
            }

            $optionName = $option->getTranslation('name');

            if (\is_string($optionName) && '' !== $optionName) {
                $optionNames[] = $optionName;
            }
        }

        return [] === $optionNames ? null : [$label, $optionNames];
    }

    /**
     * @param array<string, mixed> $feature
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function resolveCustomField(array $feature, SalesChannelProductEntity $product, SalesChannelContext $context): ?array
    {
        $name = $feature['name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        $customFields = $product->getTranslation('customFields');

        if (!\is_array($customFields) || !\array_key_exists($name, $customFields)) {
            return null;
        }

        return [$this->resolveCustomFieldLabel($name, $context) ?? $name, $customFields[$name]];
    }

    /**
     * @return array{0: string, 1: mixed}|null
     */
    private function resolveReferencePrice(SalesChannelProductEntity $product, SalesChannelContext $context): ?array
    {
        $referencePrice = $product->getCalculatedPrice()->getReferencePrice();

        if (null === $referencePrice) {
            return null;
        }

        $formattedPrice = number_format($referencePrice->getPrice(), 2, '.', '').' '.$context->getCurrency()->getIsoCode();

        $value = \sprintf(
            '%s per %s %s',
            $formattedPrice,
            $this->stringify($referencePrice->getReferenceUnit()),
            $referencePrice->getUnitName()
        );

        return ['Reference price', trim($value)];
    }

    private function resolveCustomFieldLabel(string $name, SalesChannelContext $context): ?string
    {
        $customField = $this->loadCustomField($name, $context);

        if (null === $customField) {
            return null;
        }

        $localeCode = $this->systemLocaleCode ??= $this->languageLocaleProvider->getLocaleForLanguageId(Defaults::LANGUAGE_SYSTEM);
        $label = $customField->getConfig()['label'][$localeCode] ?? null;

        return \is_string($label) && '' !== $label ? $label : null;
    }

    private function loadCustomField(string $name, SalesChannelContext $context): ?CustomFieldEntity
    {
        if (\array_key_exists($name, $this->customFieldCache)) {
            return $this->customFieldCache[$name];
        }

        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', $name));

        return $this->customFieldCache[$name] = $this->customFieldRepository
            ->search($criteria, $context->getContext())
            ->getEntities()
            ->first();
    }

    private function resolveSection(ProductFeatureSetEntity $featureSet): string
    {
        $name = $featureSet->getTranslation('name');

        return \is_string($name) && '' !== trim($name) ? $name : 'Specifications';
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        if (\is_array($value)) {
            $parts = array_filter(
                array_map(fn (mixed $entry): string => $this->stringify($entry), $value),
                static fn (string $part): bool => '' !== $part
            );

            return implode(', ', $parts);
        }

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
