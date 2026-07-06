<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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
    private EssentialCharacteristicsResolver&MockObject $characteristicsResolver;

    private ProductMeasurementsResolver&MockObject $measurementsResolver;

    private AgenticProductExportExtension $extension;

    protected function setUp(): void
    {
        $this->characteristicsResolver = $this->createMock(EssentialCharacteristicsResolver::class);
        $this->measurementsResolver = $this->createMock(ProductMeasurementsResolver::class);
        $this->extension = new AgenticProductExportExtension($this->characteristicsResolver, $this->measurementsResolver);
    }

    public function testRegistersBothFunctions(): void
    {
        $names = array_map(static fn ($function) => $function->getName(), $this->extension->getFunctions());

        static::assertContains('agentic_essential_characteristics', $names);
        static::assertContains('agentic_product_measurements', $names);
    }

    public function testDelegatesCharacteristicsToResolver(): void
    {
        $product = $this->createProduct();
        $context = $this->createContext();
        $expected = [['section' => 'Specifications', 'name' => 'ean', 'value' => '123']];

        $this->characteristicsResolver->expects(static::once())
            ->method('resolve')
            ->with($product, $context)
            ->willReturn($expected);

        static::assertSame($expected, $this->extension->getEssentialCharacteristics($product, $context));
    }

    public function testCharacteristicsReturnEmptyForUnexpectedArguments(): void
    {
        $this->characteristicsResolver->expects(static::never())->method('resolve');

        static::assertSame([], $this->extension->getEssentialCharacteristics(null, null));
        static::assertSame([], $this->extension->getEssentialCharacteristics($this->createProduct(), 'not-a-context'));
    }

    public function testDelegatesMeasurementsToResolver(): void
    {
        $product = $this->createProduct();
        $expected = [
            'weight' => ['value' => '1000', 'unit' => 'kg', 'display' => '1000 kg'],
            'length' => ['value' => '10', 'unit' => 'cm', 'display' => '10 cm'],
            'width' => ['value' => '64.2', 'unit' => 'cm', 'display' => '64.2 cm'],
            'height' => ['value' => '82.5', 'unit' => 'cm', 'display' => '82.5 cm'],
            'unitPricingMeasure' => null,
            'unitPricingBaseMeasure' => null,
        ];

        $this->measurementsResolver->expects(static::once())
            ->method('resolve')
            ->with($product)
            ->willReturn($expected);

        static::assertSame($expected, $this->extension->getProductMeasurements($product));
    }

    public function testMeasurementsReturnEmptyStructForUnexpectedArgument(): void
    {
        $this->measurementsResolver->expects(static::never())->method('resolve');

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
