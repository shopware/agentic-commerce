<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadDefinition;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Swag\AgenticCommerce\TestData\TestDataIds;

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
    public const GROUP_FORMAT = 'property-group.format';
    public const GROUP_MATERIAL = 'property-group.material';
    public const CUSTOM_FIELD_SET = 'custom-field-set';
    public const CUSTOM_FIELD_SET_NAME = 'swag_ac_test';
    public const CUSTOM_FIELD_CARE_NOTE = 'swag_ac_test_care_note';
    public const CUSTOM_FIELD_WARRANTY_YEARS = 'swag_ac_test_warranty_years';
    public const CUSTOM_FIELD_RECYCLABLE = 'swag_ac_test_recyclable';
    public const MEDIA_GUIDE = 'media.guide';
    public const MEDIA_ALBUM = 'media.album';

    public const FORMAT_OPTIONS = [
        'format.printed-a5' => 'Printed A5',
        'format.printed-a4' => 'Printed A4',
        'format.pdf' => 'PDF',
        'format.mp3' => 'MP3',
        'format.flac' => 'FLAC',
        'format.vinyl' => 'Vinyl',
    ];

    public const MATERIAL_OPTIONS = [
        'material.recycled-paper' => 'Recycled paper',
        'material.linen' => 'Linen',
    ];

    /**
     * @param EntityRepository<RuleCollection>           $ruleRepository
     * @param EntityRepository<PropertyGroupCollection>  $propertyGroupRepository
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     * @param EntityRepository<MediaCollection>          $mediaRepository
     * @param EntityRepository<MediaFolderCollection>    $mediaFolderRepository
     */
    public function __construct(
        private readonly EntityRepository $ruleRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $mediaFolderRepository,
        private readonly MediaService $mediaService,
    ) {
    }

    public function label(): string
    {
        return 'Pricing rule, property groups, custom fields, download files';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->mediaRepository, TestDataIds::id(self::MEDIA_ALBUM), $context);
    }

    public function create(array $salesChannelIds, Context $context): array
    {
        $this->ruleRepository->upsert([
            [
                'id' => TestDataIds::id(self::RULE_SALES_CHANNEL),
                'name' => TestDataIds::name('Target sales channels'),
                'description' => 'Drives the advanced prices, so every cart in the target sales channels qualifies.',
                'priority' => 100,
                'customFields' => TestDataIds::marker(),
                'conditions' => RuleConditions::single(self::RULE_SALES_CHANNEL, SalesChannelRule::RULE_NAME, [
                    'operator' => Rule::OPERATOR_EQ,
                    'salesChannelIds' => $salesChannelIds,
                ]),
            ],
        ], $context);

        $this->propertyGroupRepository->upsert([
            $this->propertyGroup(self::GROUP_FORMAT, 'Format', self::FORMAT_OPTIONS),
            $this->propertyGroup(self::GROUP_MATERIAL, 'Material', self::MATERIAL_OPTIONS),
        ], $context);

        $this->customFieldSetRepository->upsert([[
            'id' => TestDataIds::id(self::CUSTOM_FIELD_SET),
            'name' => self::CUSTOM_FIELD_SET_NAME,
            'config' => ['label' => ['en-GB' => TestDataIds::name('Agentic Commerce'), 'de-DE' => TestDataIds::name('Agentic Commerce')]],
            'relations' => [['id' => TestDataIds::id(self::CUSTOM_FIELD_SET.'.relation.product'), 'entityName' => 'product']],
            'customFields' => [
                $this->customField(self::CUSTOM_FIELD_CARE_NOTE, CustomFieldTypes::TEXT, 'Care note', 'Pflegehinweis', 1),
                $this->customField(self::CUSTOM_FIELD_WARRANTY_YEARS, CustomFieldTypes::INT, 'Warranty (years)', 'Garantie (Jahre)', 2),
                $this->customField(self::CUSTOM_FIELD_RECYCLABLE, CustomFieldTypes::BOOL, 'Recyclable', 'Recycelbar', 3),
            ],
        ]], $context);

        $this->createDownload(self::MEDIA_GUIDE, 'swag-ac-test-guide', 'pdf', 'application/pdf', self::minimalPdf(), $context);
        $this->createDownload(self::MEDIA_ALBUM, 'swag-ac-test-album', 'txt', 'text/plain', "Agentic Commerce test album download.\n", $context);

        return [
            'Rule: '.TestDataIds::name('Target sales channels'),
            'Property groups: Format ('.implode(', ', self::FORMAT_OPTIONS).'), Material ('.implode(', ', self::MATERIAL_OPTIONS).')',
            'Custom field set: '.self::CUSTOM_FIELD_SET_NAME,
            'Downloads: swag-ac-test-guide.pdf, swag-ac-test-album.txt',
        ];
    }

    public function remove(Context $context): bool
    {
        $removed = $this->deleteExisting($this->mediaRepository, [TestDataIds::id(self::MEDIA_GUIDE), TestDataIds::id(self::MEDIA_ALBUM)], $context);
        $removed = $this->deleteExisting($this->customFieldSetRepository, [TestDataIds::id(self::CUSTOM_FIELD_SET)], $context) || $removed;
        $removed = $this->deleteExisting($this->propertyGroupRepository, [TestDataIds::id(self::GROUP_FORMAT), TestDataIds::id(self::GROUP_MATERIAL)], $context) || $removed;

        return $this->deleteExisting($this->ruleRepository, [TestDataIds::id(self::RULE_SALES_CHANNEL)], $context) || $removed;
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function propertyGroup(string $groupKey, string $name, array $options): array
    {
        $optionPayloads = [];
        foreach (array_keys($options) as $position => $optionKey) {
            $optionPayloads[] = ['id' => TestDataIds::id($optionKey), 'name' => $options[$optionKey], 'position' => $position + 1];
        }

        return [
            'id' => TestDataIds::id($groupKey),
            'name' => $name,
            'displayType' => PropertyGroupDefinition::DISPLAY_TYPE_TEXT,
            'sortingType' => PropertyGroupDefinition::SORTING_TYPE_POSITION,
            'filterable' => true,
            'customFields' => TestDataIds::marker(),
            'options' => $optionPayloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customField(string $name, string $type, string $labelEn, string $labelDe, int $position): array
    {
        return [
            'id' => TestDataIds::id('custom-field.'.$name),
            'name' => $name,
            'type' => $type,
            'config' => [
                'label' => ['en-GB' => $labelEn, 'de-DE' => $labelDe],
                'customFieldPosition' => $position,
            ],
        ];
    }

    private function createDownload(string $mediaKey, string $fileName, string $extension, string $contentType, string $blob, Context $context): void
    {
        $mediaId = TestDataIds::id($mediaKey);

        $this->mediaRepository->upsert([[
            'id' => $mediaId,
            'mediaFolderId' => $this->downloadFolderId($context),
            'private' => true,
            'title' => TestDataIds::name($fileName),
            'customFields' => TestDataIds::marker(),
        ]], $context);

        if ($this->mediaRepository->search(new Criteria([$mediaId]), $context)->getEntities()->first()?->hasFile()) {
            return;
        }

        $this->mediaService->saveFile($blob, $extension, $contentType, $fileName, $context, null, $mediaId, true);
    }

    private function downloadFolderId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('defaultFolder.entity', ProductDownloadDefinition::ENTITY_NAME))
            ->setLimit(1);

        return $this->mediaFolderRepository->searchIds($criteria, $context)->firstId();
    }

    private static function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
