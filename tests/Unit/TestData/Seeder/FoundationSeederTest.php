<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\RuleConditions;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * @internal
 */
#[CoversClass(FoundationSeeder::class)]
#[CoversClass(RuleConditions::class)]
class FoundationSeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const DOWNLOAD_FOLDER_ID = '0191eeeeeeee7000eeeeeeeeeeeeeeee';

    /** @var StaticEntityRepository<RuleCollection> */
    private StaticEntityRepository $ruleRepository;

    /** @var StaticEntityRepository<PropertyGroupCollection> */
    private StaticEntityRepository $propertyGroupRepository;

    /** @var StaticEntityRepository<CustomFieldSetCollection> */
    private StaticEntityRepository $customFieldSetRepository;

    /** @var StaticEntityRepository<MediaCollection> */
    private StaticEntityRepository $mediaRepository;

    private MediaService&MockObject $mediaService;

    protected function setUp(): void
    {
        $this->ruleRepository = new StaticEntityRepository([]);
        $this->propertyGroupRepository = new StaticEntityRepository([]);
        $this->customFieldSetRepository = new StaticEntityRepository([]);
        $this->mediaRepository = new StaticEntityRepository([new MediaCollection(), new MediaCollection()]);
        $this->mediaService = $this->createMock(MediaService::class);
    }

    public function testRuleTargetsTheGivenSalesChannelsInsideTheBuilderContainers(): void
    {
        $this->seeder()->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());

        $rule = $this->ruleRepository->upserts[0][0];
        static::assertSame(TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL), $rule['id']);
        static::assertTrue($rule['customFields'][TestDataIds::MARKER]);

        $or = $rule['conditions'][0];
        $and = $or['children'][0];
        $condition = $and['children'][0];
        static::assertSame(['orContainer', 'andContainer', SalesChannelRule::RULE_NAME], [$or['type'], $and['type'], $condition['type']]);
        static::assertSame(['operator' => '=', 'salesChannelIds' => [self::SALES_CHANNEL_ID]], $condition['value']);
    }

    public function testPropertyGroupsAndCustomFieldSetAreCreated(): void
    {
        $this->seeder()->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());

        $propertyGroupById = array_column($this->propertyGroupRepository->upserts[0], null, 'id');
        $format = $propertyGroupById[TestDataIds::id(FoundationSeeder::GROUP_FORMAT)];
        static::assertSame('Format', $format['name']);
        static::assertSame(array_values(FoundationSeeder::FORMAT_OPTIONS), array_column($format['options'], 'name'));
        static::assertSame([1, 2, 3, 4, 5, 6], array_column($format['options'], 'position'));
        static::assertSame(TestDataIds::id('format.pdf'), $format['options'][2]['id']);
        static::assertCount(2, $propertyGroupById[TestDataIds::id(FoundationSeeder::GROUP_MATERIAL)]['options']);

        $customFieldSet = $this->customFieldSetRepository->upserts[0][0];
        static::assertSame(FoundationSeeder::CUSTOM_FIELD_SET_NAME, $customFieldSet['name']);
        static::assertSame('product', $customFieldSet['relations'][0]['entityName']);
        static::assertSame(
            [FoundationSeeder::CUSTOM_FIELD_CARE_NOTE, FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS, FoundationSeeder::CUSTOM_FIELD_RECYCLABLE],
            array_column($customFieldSet['customFields'], 'name'),
        );
    }

    public function testDownloadsArePrivateMediaInTheDownloadFolder(): void
    {
        $savedFiles = [];
        $this->mediaService->expects(static::exactly(2))->method('saveFile')
            ->willReturnCallback(static function (string $blob, string $extension, string $contentType, string $fileName, Context $context, ?string $folder, ?string $mediaId, bool $private) use (&$savedFiles): string {
                $savedFiles[] = [$extension, $contentType, $fileName, $mediaId, $private];

                return (string) $mediaId;
            });

        $this->seeder()->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());

        static::assertSame([
            ['pdf', 'application/pdf', 'swag-ac-test-guide', TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), true],
            ['txt', 'text/plain', 'swag-ac-test-album', TestDataIds::id(FoundationSeeder::MEDIA_ALBUM), true],
        ], $savedFiles);

        foreach ($this->mediaRepository->upserts as [$media]) {
            static::assertTrue($media['private']);
            static::assertSame(self::DOWNLOAD_FOLDER_ID, $media['mediaFolderId']);
        }
    }

    public function testRerunKeepsDownloadFilesThatWereAlreadySaved(): void
    {
        $savedGuide = new MediaEntity();
        $savedGuide->setId(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE));
        $savedGuide->setUniqueIdentifier(TestDataIds::id(FoundationSeeder::MEDIA_GUIDE));
        $savedGuide->setPath('media/guide/swag-ac-test-guide.pdf');
        $this->mediaRepository = new StaticEntityRepository([new MediaCollection([$savedGuide]), new MediaCollection()]);

        $this->mediaService->expects(static::once())->method('saveFile')
            ->with(static::anything(), 'txt', 'text/plain', 'swag-ac-test-album')
            ->willReturn(TestDataIds::id(FoundationSeeder::MEDIA_ALBUM));

        $this->seeder()->create([self::SALES_CHANNEL_ID], Context::createDefaultContext());
    }

    public function testExistsChecksTheLastDownloadWritten(): void
    {
        $this->mediaRepository = new StaticEntityRepository([[TestDataIds::id(FoundationSeeder::MEDIA_ALBUM)], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    public function testRemoveReportsWhetherAnythingExisted(): void
    {
        $this->mediaRepository = new StaticEntityRepository([[TestDataIds::id(FoundationSeeder::MEDIA_GUIDE)], []]);
        $this->customFieldSetRepository = new StaticEntityRepository([[], []]);
        $this->propertyGroupRepository = new StaticEntityRepository([[], []]);
        $this->ruleRepository = new StaticEntityRepository([[], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => TestDataIds::id(FoundationSeeder::MEDIA_GUIDE)]]], $this->mediaRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    private function seeder(): FoundationSeeder
    {
        /** @var StaticEntityRepository<MediaFolderCollection> $mediaFolderRepository */
        $mediaFolderRepository = new StaticEntityRepository([[self::DOWNLOAD_FOLDER_ID], [self::DOWNLOAD_FOLDER_ID]]);

        return new FoundationSeeder(
            $this->ruleRepository,
            $this->propertyGroupRepository,
            $this->customFieldSetRepository,
            $this->mediaRepository,
            $mediaFolderRepository,
            $this->mediaService,
        );
    }
}
