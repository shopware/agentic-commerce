<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * The other groups reference these ids, so this group is created first and removed last.
 *
 * @internal
 */
#[Package('framework')]
final class FoundationSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const RULE_SALES_CHANNEL = 'rule.sales-channel';
    /** Always removed; any other group a catalogue brings is recorded in CREATED_GROUPS_CONFIG_KEY. */
    public const PROPERTY_GROUPS = ['format', 'material', 'colour', 'size', 'flavour'];
    public const CREATED_GROUPS_CONFIG_KEY = 'SwagAgenticCommerce.testData.propertyGroups';
    public const CUSTOM_FIELD_SET = 'custom-field-set';
    public const CUSTOM_FIELD_SET_NAME = 'swag_ac_test';
    public const CUSTOM_FIELD_CARE_NOTE = 'swag_ac_test_care_note';
    public const CUSTOM_FIELD_WARRANTY_YEARS = 'swag_ac_test_warranty_years';
    public const CUSTOM_FIELD_RECYCLABLE = 'swag_ac_test_recyclable';
    public const CUSTOM_FIELD_INGREDIENTS = 'swag_ac_test_ingredients';
    public const CUSTOM_FIELD_ALLERGENS = 'swag_ac_test_allergens';
    public const MEDIA_GUIDE = 'media.guide';
    public const MEDIA_ALBUM = 'media.album';

    /**
     * @param EntityRepository<RuleCollection>           $ruleRepository
     * @param EntityRepository<PropertyGroupCollection>  $propertyGroupRepository
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     */
    public function __construct(
        private readonly EntityRepository $ruleRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $customFieldSetRepository,
        private readonly ShopLanguagesLoader $shopLanguagesLoader,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function label(): string
    {
        return 'Pricing rule, property groups, custom fields';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->ruleRepository, TestDataIds::id(self::RULE_SALES_CHANNEL), $context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $shopLanguages = $this->shopLanguagesLoader->load($context);

        $this->ruleRepository->upsert([
            [
                'id' => TestDataIds::id(self::RULE_SALES_CHANNEL),
                'name' => TestDataIds::prefixedName('Target sales channels'),
                'description' => 'Drives the advanced prices, so every cart in the target sales channels qualifies.',
                'priority' => 100,
                'customFields' => TestDataIds::markerCustomField(),
                'conditions' => RuleConditions::single(self::RULE_SALES_CHANNEL, SalesChannelRule::RULE_NAME, [
                    'operator' => Rule::OPERATOR_EQ,
                    'salesChannelIds' => $salesChannelIds,
                ]),
            ],
        ], $context);

        $optionsByGroup = self::usedOptionsByGroup($pickedProducts);
        $groupSummaries = [];
        $groupPayloads = [];
        foreach ($optionsByGroup as $groupKey => $optionByKey) {
            $propertyGroupDefinition = $pickedProducts->catalogue->propertyGroupByKey[$groupKey];
            $groupPayloads[] = $this->propertyGroupPayload($groupKey, $propertyGroupDefinition['name'], $propertyGroupDefinition['display'], $optionByKey, $shopLanguages);
            $groupSummaries[] = \sprintf('%s (%s)', $propertyGroupDefinition['name']->english, implode(', ', array_map(static fn (array $option): string => $option['name']->english, $optionByKey)));
        }
        if ([] !== $groupPayloads) {
            $this->propertyGroupRepository->upsert($groupPayloads, $context);
            $this->systemConfigService->set(self::CREATED_GROUPS_CONFIG_KEY, array_values(array_unique([...$this->createdPropertyGroupKeys(), ...array_keys($optionsByGroup)])));
        }

        $this->customFieldSetRepository->upsert([[
            'id' => TestDataIds::id(self::CUSTOM_FIELD_SET),
            'name' => self::CUSTOM_FIELD_SET_NAME,
            'config' => ['label' => ['en-GB' => TestDataIds::prefixedName('Agentic Commerce'), 'de-DE' => TestDataIds::prefixedName('Agentic Commerce')]],
            'relations' => [['id' => TestDataIds::id(self::CUSTOM_FIELD_SET.'.relation.product'), 'entityName' => 'product']],
            'customFields' => [
                $this->customFieldPayload(self::CUSTOM_FIELD_CARE_NOTE, CustomFieldTypes::TEXT, new TranslatedText('Care note', 'Pflegehinweis'), 1),
                $this->customFieldPayload(self::CUSTOM_FIELD_WARRANTY_YEARS, CustomFieldTypes::INT, new TranslatedText('Warranty (years)', 'Garantie (Jahre)'), 2),
                $this->customFieldPayload(self::CUSTOM_FIELD_RECYCLABLE, CustomFieldTypes::BOOL, new TranslatedText('Recyclable', 'Recycelbar'), 3),
                $this->customFieldPayload(self::CUSTOM_FIELD_INGREDIENTS, CustomFieldTypes::TEXT, new TranslatedText('Ingredients', 'Zutaten'), 4),
                $this->customFieldPayload(self::CUSTOM_FIELD_ALLERGENS, CustomFieldTypes::TEXT, new TranslatedText('Allergens', 'Allergene'), 5),
            ],
        ]], $context);

        return [
            'Rule: '.TestDataIds::prefixedName('Target sales channels'),
            'Property groups: '.implode(', ', $groupSummaries),
            'Custom field set: '.self::CUSTOM_FIELD_SET_NAME,
        ];
    }

    public function remove(Context $context): bool
    {
        $hasRemovedAny = $this->deleteExisting($this->customFieldSetRepository, [TestDataIds::id(self::CUSTOM_FIELD_SET)], $context);
        $propertyGroupKeys = array_values(array_unique([...self::PROPERTY_GROUPS, ...$this->createdPropertyGroupKeys()]));
        $hasRemovedAny = $this->deleteExisting($this->propertyGroupRepository, array_map(static fn (string $propertyGroupKey): string => TestDataIds::id('property-group.'.$propertyGroupKey), $propertyGroupKeys), $context) || $hasRemovedAny;
        $this->systemConfigService->delete(self::CREATED_GROUPS_CONFIG_KEY);

        return $this->deleteExisting($this->ruleRepository, [TestDataIds::id(self::RULE_SALES_CHANNEL)], $context) || $hasRemovedAny;
    }

    /**
     * The first product naming an option wins.
     *
     * @return array<string, array<string, array{name: TranslatedText, color: ?string}>>
     */
    private static function usedOptionsByGroup(PickedProducts $pickedProducts): array
    {
        $optionsByGroup = [];
        foreach ($pickedProducts->productByRole as $product) {
            foreach ($product->optionKeysByGroup as $group => $optionKeys) {
                foreach ($optionKeys as $optionKey) {
                    $optionsByGroup[$group][$optionKey] ??= ['name' => $pickedProducts->catalogue->propertyGroupByKey[$group]['options'][$optionKey], 'color' => null];
                }
            }

            foreach ($product->variantOptions as $option) {
                $optionsByGroup[(string) $product->variantPropertyGroup][$option->key] ??= ['name' => $option->name, 'color' => $option->colorHexCode];
            }
        }

        return $optionsByGroup;
    }

    /**
     * @return list<string> may name groups the current catalogue no longer has
     */
    private function createdPropertyGroupKeys(): array
    {
        $storedPropertyGroupKeys = $this->systemConfigService->get(self::CREATED_GROUPS_CONFIG_KEY);

        return \is_array($storedPropertyGroupKeys) ? array_values(array_filter($storedPropertyGroupKeys, 'is_string')) : [];
    }

    /**
     * @param array<string, array{name: TranslatedText, color: ?string}> $optionByKey
     *
     * @return array<string, mixed>
     */
    private function propertyGroupPayload(string $groupKey, TranslatedText $groupName, string $display, array $optionByKey, ShopLanguages $shopLanguages): array
    {
        $optionPayloads = [];
        foreach (array_keys($optionByKey) as $position => $optionKey) {
            $optionPayloads[] = array_filter([
                'id' => TestDataIds::propertyOptionId($groupKey, $optionKey),
                ...$shopLanguages->translatedFields(['name' => $optionByKey[$optionKey]['name']]),
                'position' => $position + 1,
                'colorHexCode' => $optionByKey[$optionKey]['color'],
            ], static fn (mixed $value): bool => null !== $value);
        }

        return [
            'id' => TestDataIds::id('property-group.'.$groupKey),
            ...$shopLanguages->translatedFields(['name' => $groupName]),
            'displayType' => 'color' === $display ? PropertyGroupDefinition::DISPLAY_TYPE_COLOR : PropertyGroupDefinition::DISPLAY_TYPE_TEXT,
            'sortingType' => PropertyGroupDefinition::SORTING_TYPE_POSITION,
            'filterable' => true,
            'customFields' => TestDataIds::markerCustomField(),
            'options' => $optionPayloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldPayload(string $name, string $type, TranslatedText $label, int $position): array
    {
        return [
            'id' => TestDataIds::id('custom-field.'.$name),
            'name' => $name,
            'type' => $type,
            'config' => [
                'label' => ['en-GB' => $label->english, 'de-DE' => $label->german],
                'customFieldPosition' => $position,
            ],
        ];
    }
}
