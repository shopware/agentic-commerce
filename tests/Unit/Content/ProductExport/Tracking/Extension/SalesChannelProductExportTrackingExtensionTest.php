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
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\SalesChannelProductExportTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;

/**
 * @internal
 */
#[CoversClass(SalesChannelProductExportTrackingExtension::class)]
class SalesChannelProductExportTrackingExtensionTest extends TestCase
{
    public function testExtendsSalesChannelEntity(): void
    {
        static::assertSame(
            SalesChannelDefinition::ENTITY_NAME,
            (new SalesChannelProductExportTrackingExtension())->getEntityName(),
        );
    }

    public function testAddsBothReverseAssociations(): void
    {
        $collection = new FieldCollection();

        (new SalesChannelProductExportTrackingExtension())->extendFields($collection);

        static::assertCount(2, $collection);

        $orderField = $collection->firstWhere(
            static fn (OneToManyAssociationField $field): bool => 'salesChannelTrackingOrders' === $field->getPropertyName(),
        );
        static::assertInstanceOf(OneToManyAssociationField::class, $orderField);
        static::assertSame(SalesChannelTrackingOrderDefinition::class, $orderField->getReferenceClass());
        static::assertSame('sales_channel_id', $orderField->getReferenceField());

        $customerField = $collection->firstWhere(
            static fn (OneToManyAssociationField $field): bool => 'salesChannelTrackingCustomers' === $field->getPropertyName(),
        );
        static::assertInstanceOf(OneToManyAssociationField::class, $customerField);
        static::assertSame(SalesChannelTrackingCustomerDefinition::class, $customerField->getReferenceClass());
        static::assertSame('sales_channel_id', $customerField->getReferenceField());
    }
}
