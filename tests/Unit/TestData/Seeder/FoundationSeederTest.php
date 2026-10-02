<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Catalogue\Catalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Catalogue\ProductPicker;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\RuleConditions;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Swag\AgenticCommerce\TestData\TranslatedText;
use Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue\CatalogueFixture;

/**
 * @internal
 */
#[CoversClass(FoundationSeeder::class)]
#[CoversClass(RuleConditions::class)]
class FoundationSeederTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    /** @var StaticEntityRepository<RuleCollection> */
    private StaticEntityRepository $ruleRepository;

    /** @var StaticEntityRepository<PropertyGroupCollection> */
    private StaticEntityRepository $propertyGroupRepository;

    /** @var StaticEntityRepository<CustomFieldSetCollection> */
    private StaticEntityRepository $customFieldSetRepository;

    private StaticSystemConfigService $systemConfig;

    protected function setUp(): void
    {
        $this->ruleRepository = new StaticEntityRepository([]);
        $this->propertyGroupRepository = new StaticEntityRepository([]);
        $this->customFieldSetRepository = new StaticEntityRepository([]);
        $this->systemConfig = new StaticSystemConfigService();
    }

    public function testRuleTargetsTheGivenSalesChannelsInsideTheBuilderContainers(): void
    {
        $this->seeder()->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        $rule = $this->ruleRepository->upserts[0][0];
        static::assertSame(TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL), $rule['id']);
        static::assertTrue($rule['customFields'][TestDataIds::MARKER]);

        $or = $rule['conditions'][0];
        $and = $or['children'][0];
        $condition = $and['children'][0];
        static::assertSame(['orContainer', 'andContainer', SalesChannelRule::RULE_NAME], [$or['type'], $and['type'], $condition['type']]);
        static::assertSame(['operator' => '=', 'salesChannelIds' => [self::SALES_CHANNEL_ID]], $condition['value']);
    }

    public function testBuiltInProductsGetTheFormatAndMaterialGroupsAndTheCustomFieldSet(): void
    {
        $report = $this->seeder()->create([self::SALES_CHANNEL_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        $propertyGroupById = array_column($this->propertyGroupRepository->upserts[0], null, 'id');
        static::assertSame([TestDataIds::id('property-group.format'), TestDataIds::id('property-group.material')], array_keys($propertyGroupById));
        $format = $propertyGroupById[TestDataIds::id('property-group.format')];
        static::assertSame('Format', $format['name']);
        static::assertSame(['Printed A5', 'Printed A4', 'PDF', 'MP3', 'FLAC', 'Vinyl'], array_column($format['options'], 'name'));
        static::assertSame([1, 2, 3, 4, 5, 6], array_column($format['options'], 'position'));
        static::assertSame(TestDataIds::propertyOptionId('format', 'pdf'), $format['options'][2]['id']);
        static::assertSame(['Recycled paper', 'Linen'], array_column($propertyGroupById[TestDataIds::id('property-group.material')]['options'], 'name'));
        static::assertSame('Property groups: Format (Printed A5, Printed A4, PDF, MP3, FLAC, Vinyl), Material (Recycled paper, Linen)', $report[1]);

        $customFieldSet = $this->customFieldSetRepository->upserts[0][0];
        static::assertSame(FoundationSeeder::CUSTOM_FIELD_SET_NAME, $customFieldSet['name']);
        static::assertSame('product', $customFieldSet['relations'][0]['entityName']);
        static::assertSame(
            [FoundationSeeder::CUSTOM_FIELD_CARE_NOTE, FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS, FoundationSeeder::CUSTOM_FIELD_RECYCLABLE, FoundationSeeder::CUSTOM_FIELD_INGREDIENTS, FoundationSeeder::CUSTOM_FIELD_ALLERGENS],
            array_column($customFieldSet['customFields'], 'name'),
        );
    }

    public function testCatalogueColourOptionsBecomeSwatchesWithGermanNames(): void
    {
        $path = sys_get_temp_dir().'/swag-ac-foundation-'.bin2hex(random_bytes(4)).'.zip';
        $pickedProducts = (new ProductPicker())->pick(CatalogueArchive::open(CatalogueFixture::writeZip($path, CatalogueFixture::catalogue())), 1);
        unlink($path);

        $this->seeder(hasGerman: true)->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());

        $colour = array_column($this->propertyGroupRepository->upserts[0], null, 'id')[TestDataIds::id('property-group.colour')];
        static::assertSame(PropertyGroupDefinition::DISPLAY_TYPE_COLOR, $colour['displayType']);
        static::assertSame(['name' => 'Farbe'], $colour['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);
        $cream = array_column($colour['options'], null, 'id')[TestDataIds::propertyOptionId('colour', 'cream')];
        static::assertSame('#F3EAD8', $cream['colorHexCode']);
        static::assertSame(['name' => 'DE cream'], $cream['translations'][ReferencesFixture::GERMAN_LANGUAGE_ID]);
    }

    public function testAContributedPropertyGroupIsCreatedAndRecordedForRemoval(): void
    {
        $product = new CatalogueProduct('scented-candle', CatalogueProduct::TYPE_PHYSICAL, new TranslatedText('Scented Candle', 'Duftkerze'), 1.0, 1, optionKeysByGroup: ['scent' => ['rose']]);
        $pickedProducts = new PickedProducts(new Catalogue([$product], ['scent' => ['name' => new TranslatedText('Scent', 'Duft'), 'display' => 'text', 'options' => ['rose' => new TranslatedText('Rose', 'Rose')]]]), [ProductSeeder::PROPERTIES => $product]);
        $this->systemConfig->set(FoundationSeeder::CREATED_GROUPS_CONFIG_KEY, ['material']);

        $this->seeder()->create([self::SALES_CHANNEL_ID], $pickedProducts, Context::createDefaultContext());

        static::assertSame(TestDataIds::id('property-group.scent'), $this->propertyGroupRepository->upserts[0][0]['id']);
        static::assertSame(['material', 'scent'], $this->systemConfig->get(FoundationSeeder::CREATED_GROUPS_CONFIG_KEY));
    }

    public function testRemoveDeletesTheKnownAndTheRecordedGroupsAndReportsWhetherAnythingExisted(): void
    {
        $this->systemConfig->set(FoundationSeeder::CREATED_GROUPS_CONFIG_KEY, ['scent']);
        $this->customFieldSetRepository = new StaticEntityRepository([[TestDataIds::id(FoundationSeeder::CUSTOM_FIELD_SET)], []]);
        $this->propertyGroupRepository = new StaticEntityRepository([
            static function (Criteria $criteria): array {
                static::assertContains(TestDataIds::id('property-group.scent'), $criteria->getIds());

                return [TestDataIds::id('property-group.colour'), TestDataIds::id('property-group.scent')];
            },
            [],
        ]);
        $this->ruleRepository = new StaticEntityRepository([[], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => TestDataIds::id('property-group.colour')], ['id' => TestDataIds::id('property-group.scent')]]], $this->propertyGroupRepository->deletes);
        static::assertNull($this->systemConfig->get(FoundationSeeder::CREATED_GROUPS_CONFIG_KEY));
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    public function testExistsChecksTheRule(): void
    {
        $this->ruleRepository = new StaticEntityRepository([[TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL)], []]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    private function seeder(bool $hasGerman = false): FoundationSeeder
    {
        return new FoundationSeeder($this->ruleRepository, $this->propertyGroupRepository, $this->customFieldSetRepository, ReferencesFixture::shopLanguagesLoader($hasGerman), $this->systemConfig);
    }
}
