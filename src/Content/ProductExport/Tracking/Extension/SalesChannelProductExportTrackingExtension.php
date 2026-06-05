<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension;

use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;

/**
 * Reverse-side associations on `sales_channel` for tracked orders/customers.
 * Powers product-export insights aggregation in the admin.
 */
class SalesChannelProductExportTrackingExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToManyAssociationField(
                'salesChannelTrackingOrders',
                SalesChannelTrackingOrderDefinition::class,
                'sales_channel_id',
                'id',
            ),
        );

        $collection->add(
            new OneToManyAssociationField(
                'salesChannelTrackingCustomers',
                SalesChannelTrackingCustomerDefinition::class,
                'sales_channel_id',
                'id',
            ),
        );
    }

    public function getEntityName(): string
    {
        return SalesChannelDefinition::ENTITY_NAME;
    }

    public function getDefinitionClass(): string
    {
        return SalesChannelDefinition::class;
    }
}
