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
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\CustomerSalesChannelTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;

/**
 * @internal
 */
#[CoversClass(CustomerSalesChannelTrackingExtension::class)]
class CustomerSalesChannelTrackingExtensionTest extends TestCase
{
    public function testExtendsCustomerEntity(): void
    {
        static::assertSame(CustomerDefinition::ENTITY_NAME, (new CustomerSalesChannelTrackingExtension())->getEntityName());
    }

    public function testAddsSalesChannelTrackingAssociationWithCascadeDelete(): void
    {
        $collection = new FieldCollection();

        (new CustomerSalesChannelTrackingExtension())->extendFields($collection);

        static::assertCount(1, $collection);

        $field = $collection->first();
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
        static::assertSame('salesChannelTracking', $field->getPropertyName());
        static::assertSame(SalesChannelTrackingCustomerDefinition::class, $field->getReferenceClass());
        static::assertTrue($field->is(CascadeDelete::class));
    }
}
