<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\ReferencePrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\Content\ProductExport\Service\EssentialCharacteristicsResolver;
use Swag\AgenticCommerce\Tests\TestGenerator as Generator;

/**
 * @internal
 */
#[CoversClass(EssentialCharacteristicsResolver::class)]
class EssentialCharacteristicsResolverTest extends TestCase
{
    private const LOCALE = 'en-GB';

    public function testReturnsEmptyWhenProductHasNoFeatureSet(): void
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());

        static::assertSame([], $this->createResolver()->resolve($product, $this->createContext()));
    }

    public function testReturnsEmptyWhenFeatureSetHasNoFeatures(): void
    {
        $product = $this->createProduct($this->createFeatureSet([]));

        static::assertSame([], $this->createResolver()->resolve($product, $this->createContext()));
    }

    public function testResolvesProductAttributeFromTranslatedValue(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'ean'),
        ]));
        $product->setTranslated(['ean' => '4006381333931']);

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'ean', 'value' => '4006381333931']],
            $this->createResolver()->resolve($product, $this->createContext())
        );
    }

    public function testResolvesPropertyGroupWithJoinedOptionNames(): void
    {
        $groupId = Uuid::randomHex();
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_PROPERTY, id: $groupId),
        ]));
        $product->setProperties(new PropertyGroupOptionCollection([
            $this->option($groupId, 'Colour', 'Red'),
            $this->option($groupId, 'Colour', 'Blue'),
        ]));

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'Colour', 'value' => 'Red, Blue']],
            $this->createResolver()->resolve($product, $this->createContext())
        );
    }

    public function testResolvesCustomFieldUsingConfiguredLabel(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_CUSTOM_FIELD, name: 'spec_weight'),
        ]));
        $product->setTranslated(['customFields' => ['spec_weight' => '1.5 kg']]);

        $customField = new CustomFieldEntity();
        $customField->setId(Uuid::randomHex());
        $customField->setName('spec_weight');
        $customField->setConfig(['label' => [self::LOCALE => 'Weight']]);

        $result = $this->createResolver([new CustomFieldCollection([$customField])])
            ->resolve($product, $this->createContext());

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'Weight', 'value' => '1.5 kg']],
            $result
        );
    }

    public function testResolvesCustomFieldFallsBackToTechnicalNameWhenNoLabel(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_CUSTOM_FIELD, name: 'spec_weight'),
        ]));
        $product->setTranslated(['customFields' => ['spec_weight' => '1.5 kg']]);

        $result = $this->createResolver([new CustomFieldCollection([])])
            ->resolve($product, $this->createContext());

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'spec_weight', 'value' => '1.5 kg']],
            $result
        );
    }

    public function testResolvesReferencePrice(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_REFERENCE_PRICE),
        ]));
        $product->setCalculatedPrice(new CalculatedPrice(
            10.0,
            10.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            1,
            new ReferencePrice(2.5, 100.0, 100.0, 'ml')
        ));

        $result = $this->createResolver()->resolve($product, $this->createContext());

        static::assertCount(1, $result);
        static::assertSame('Reference price', $result[0]['name']);
        static::assertStringContainsString('2.50', $result[0]['value']);
        static::assertStringContainsString('ml', $result[0]['value']);
    }

    public function testReferencePriceIsSkippedWhenCalculatedPriceIsUninitialised(): void
    {
        // A product loaded without price calculation leaves the non-nullable
        // `calculatedPrice` typed property uninitialised; resolution must skip the
        // characteristic instead of throwing and interrupting the whole feed.
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_REFERENCE_PRICE),
        ]));

        static::assertSame([], $this->createResolver()->resolve($product, $this->createContext()));
    }

    public function testOrdersCharacteristicsByPosition(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'second', position: 5),
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'first', position: 1),
        ]));
        $product->setTranslated(['first' => 'A', 'second' => 'B']);

        $names = array_column($this->createResolver()->resolve($product, $this->createContext()), 'name');

        static::assertSame(['first', 'second'], $names);
    }

    public function testDropsEmptyValuesAndStringifiesScalars(): void
    {
        $product = $this->createProduct($this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'blank', position: 1),
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'flag', position: 2),
        ]));
        $product->setTranslated(['blank' => '   ', 'flag' => true]);

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'flag', 'value' => 'yes']],
            $this->createResolver()->resolve($product, $this->createContext())
        );
    }

    public function testUsesFeatureSetNameAsSection(): void
    {
        $featureSet = $this->createFeatureSet([
            $this->feature(ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, name: 'ean'),
        ]);
        $featureSet->setTranslated(['name' => 'Technical data']);
        $product = $this->createProduct($featureSet);
        $product->setTranslated(['ean' => '123']);

        $result = $this->createResolver()->resolve($product, $this->createContext());

        static::assertSame('Technical data', $result[0]['section']);
    }

    /**
     * @param list<CustomFieldCollection> $customFieldSearches
     */
    private function createResolver(array $customFieldSearches = []): EssentialCharacteristicsResolver
    {
        /** @var StaticEntityRepository<CustomFieldCollection> $repository */
        $repository = new StaticEntityRepository($customFieldSearches);

        $localeProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $localeProvider->method('getLocaleForLanguageId')->willReturn(self::LOCALE);

        return new EssentialCharacteristicsResolver($repository, $localeProvider);
    }

    /**
     * @param list<array{name: string, id: string, type: string, position: int}> $features
     */
    private function createFeatureSet(array $features): ProductFeatureSetEntity
    {
        $featureSet = new ProductFeatureSetEntity();
        $featureSet->setId(Uuid::randomHex());
        $featureSet->setTranslated(['name' => 'Specifications']);
        $featureSet->setFeatures($features);

        return $featureSet;
    }

    private function createProduct(ProductFeatureSetEntity $featureSet): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());
        $product->setFeatureSet($featureSet);

        return $product;
    }

    /**
     * @return array{name: string, id: string, type: string, position: int}
     */
    private function feature(string $type, string $name = '', string $id = '', int $position = 1): array
    {
        return ['name' => $name, 'id' => $id, 'type' => $type, 'position' => $position];
    }

    private function option(string $groupId, string $groupName, string $optionName): PropertyGroupOptionEntity
    {
        $group = new PropertyGroupEntity();
        $group->setId($groupId);
        $group->setTranslated(['name' => $groupName]);

        $option = new PropertyGroupOptionEntity();
        $option->setId(Uuid::randomHex());
        $option->setGroupId($groupId);
        $option->setGroup($group);
        $option->setTranslated(['name' => $optionName]);

        return $option;
    }

    private function createContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(Uuid::randomHex());
        $salesChannel->setName('Agentic');

        $context = Generator::generateSalesChannelContext(
            baseContext: Context::createDefaultContext(),
            salesChannel: $salesChannel
        );

        // The test Generator builds a currency without an ISO code; a real
        // sales-channel context always carries one (used for reference-price output).
        $context->getCurrency()->setIsoCode('EUR');

        return $context;
    }
}
