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
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Unit\UnitEntity;
use Swag\AgenticCommerce\Content\ProductExport\Service\ProductMeasurementsResolver;

/**
 * @internal
 */
#[CoversClass(ProductMeasurementsResolver::class)]
class ProductMeasurementsResolverTest extends TestCase
{
    public function testExportsWeightInKilograms(): void
    {
        $product = $this->createProduct();
        $product->setWeight(1000.0);

        static::assertSame(
            ['value' => '1000', 'unit' => 'kg', 'display' => '1000 kg'],
            $this->resolve($product)['weight']
        );
    }

    public function testConvertsDimensionsFromMillimetresToCentimetres(): void
    {
        $product = $this->createProduct();
        $product->setWidth(642.0);
        $product->setHeight(825.0);
        $product->setLength(100.0);

        $result = $this->resolve($product);

        static::assertSame(['value' => '64.2', 'unit' => 'cm', 'display' => '64.2 cm'], $result['width']);
        static::assertSame(['value' => '82.5', 'unit' => 'cm', 'display' => '82.5 cm'], $result['height']);
        static::assertSame(['value' => '10', 'unit' => 'cm', 'display' => '10 cm'], $result['length']);
    }

    public function testReturnsNullForMissingOrZeroValues(): void
    {
        $product = $this->createProduct();
        $product->setWeight(0.0);
        $product->setWidth(null);

        $result = $this->resolve($product);

        static::assertNull($result['weight']);
        static::assertNull($result['width']);
        static::assertNull($result['height']);
        static::assertNull($result['length']);
        static::assertNull($result['unitPricingMeasure']);
        static::assertNull($result['unitPricingBaseMeasure']);
    }

    public function testExportsUnitPricingWhenUnitIsAssigned(): void
    {
        $product = $this->createProduct();
        $product->setPurchaseUnit(1.5);
        $product->setReferenceUnit(100.0);
        $product->setUnit($this->createUnit('ml'));

        $result = $this->resolve($product);

        static::assertSame('1.5 ml', $result['unitPricingMeasure']);
        static::assertSame('100 ml', $result['unitPricingBaseMeasure']);
    }

    public function testOmitsUnitPricingWhenNoUnitAssigned(): void
    {
        $product = $this->createProduct();
        $product->setPurchaseUnit(1.5);
        $product->setReferenceUnit(100.0);

        $result = $this->resolve($product);

        static::assertNull($result['unitPricingMeasure']);
        static::assertNull($result['unitPricingBaseMeasure']);
    }

    public function testTrimsTrailingZeros(): void
    {
        $product = $this->createProduct();
        $product->setWeight(2.5000);
        $product->setWidth(500.0);

        $result = $this->resolve($product);

        static::assertSame('2.5', $result['weight']['value']);
        static::assertSame('2.5 kg', $result['weight']['display']);
        static::assertSame('50 cm', $result['width']['display']);
    }

    /**
     * @return array{weight: ?array{value: string, unit: string, display: string}, length: ?array{value: string, unit: string, display: string}, width: ?array{value: string, unit: string, display: string}, height: ?array{value: string, unit: string, display: string}, unitPricingMeasure: ?string, unitPricingBaseMeasure: ?string}
     */
    private function resolve(SalesChannelProductEntity $product): array
    {
        return (new ProductMeasurementsResolver())->resolve($product);
    }

    private function createProduct(): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId(Uuid::randomHex());

        return $product;
    }

    private function createUnit(string $name): UnitEntity
    {
        $unit = new UnitEntity();
        $unit->setId(Uuid::randomHex());
        $unit->setTranslated(['name' => $name]);

        return $unit;
    }
}
