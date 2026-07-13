<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\Content\ProductExport\Service\EssentialCharacteristicsResolver;
use Swag\AgenticCommerce\Content\ProductExport\Service\ProductMeasurementsResolver;
use Swag\AgenticCommerce\Content\ProductExport\Twig\AgenticProductExportExtension;
use Swag\AgenticCommerce\Tests\TestGenerator as Generator;

/**
 * @internal
 */
#[CoversClass(AgenticProductExportExtension::class)]
class AgenticProductExportExtensionTest extends TestCase
{
    private AgenticProductExportExtension $extension;

    protected function setUp(): void
    {
        /** @var StaticEntityRepository<CustomFieldCollection> $customFieldRepository */
        $customFieldRepository = new StaticEntityRepository([]);

        $characteristicsResolver = new EssentialCharacteristicsResolver(
            $customFieldRepository,
            $this->createMock(LanguageLocaleCodeProvider::class),
        );

        $this->extension = new AgenticProductExportExtension($characteristicsResolver, new ProductMeasurementsResolver());
    }

    public function testRegistersBothFunctions(): void
    {
        $names = array_map(static fn ($function) => $function->getName(), $this->extension->getFunctions());

        static::assertContains('agentic_essential_characteristics', $names);
        static::assertContains('agentic_product_measurements', $names);
    }

    public function testDelegatesCharacteristicsToResolver(): void
    {
        $featureSet = new ProductFeatureSetEntity();
        $featureSet->setId(Uuid::randomHex());
        $featureSet->setTranslated(['name' => 'Specifications']);
        $featureSet->setFeatures([
            ['id' => '', 'name' => 'ean', 'type' => ProductFeatureSetDefinition::TYPE_PRODUCT_ATTRIBUTE, 'position' => 1],
        ]);

        $product = $this->createProduct();
        $product->setFeatureSet($featureSet);
        $product->setTranslated(['ean' => '123']);

        static::assertSame(
            [['section' => 'Specifications', 'name' => 'ean', 'value' => '123']],
            $this->extension->getEssentialCharacteristics($product, $this->createContext())
        );
    }

    public function testCharacteristicsReturnEmptyForUnexpectedArguments(): void
    {
        static::assertSame([], $this->extension->getEssentialCharacteristics(null, null));
        static::assertSame([], $this->extension->getEssentialCharacteristics($this->createProduct(), 'not-a-context'));
    }

    public function testDelegatesMeasurementsToResolver(): void
    {
        $product = $this->createProduct();
        $product->setWeight(1000.0);

        $measurements = $this->extension->getProductMeasurements($product);

        static::assertSame(['value' => '1000', 'unit' => 'kg', 'display' => '1000 kg'], $measurements['weight']);
    }

    public function testMeasurementsReturnEmptyStructForUnexpectedArgument(): void
    {
        static::assertSame([
            'weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'unitPricingMeasure' => null,
            'unitPricingBaseMeasure' => null,
        ], $this->extension->getProductMeasurements('not-a-product'));
    }

    private function createProduct(): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());

        return $product;
    }

    private function createContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(Uuid::randomHex());
        $salesChannel->setName('Agentic');

        return Generator::generateSalesChannelContext(
            baseContext: Context::createDefaultContext(),
            salesChannel: $salesChannel
        );
    }
}
