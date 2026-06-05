<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Tracking\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\OrderSalesChannelTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;

/**
 * @internal
 */
#[CoversClass(OrderSalesChannelTrackingExtension::class)]
class OrderSalesChannelTrackingExtensionTest extends TestCase
{
    public function testExtendsOrderEntity(): void
    {
        static::assertSame(OrderDefinition::ENTITY_NAME, (new OrderSalesChannelTrackingExtension())->getEntityName());
    }

    public function testAddsSalesChannelTrackingAssociation(): void
    {
        $collection = new FieldCollection();

        (new OrderSalesChannelTrackingExtension())->extendFields($collection);

        static::assertCount(1, $collection);

        $field = $collection->first();
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
        static::assertSame('salesChannelTracking', $field->getPropertyName());
        static::assertSame(SalesChannelTrackingOrderDefinition::class, $field->getReferenceClass());
    }
}
