<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;

/**
 * Adds the `salesChannelTracking` association on the `order` entity so the admin
 * order list can read `extensions.salesChannelTracking.salesChannel.name`.
 */
class OrderSalesChannelTrackingExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            new OneToOneAssociationField(
                'salesChannelTracking',
                'id',
                'order_id',
                SalesChannelTrackingOrderDefinition::class,
                false,
            ),
        );
    }

    public function getEntityName(): string
    {
        return OrderDefinition::ENTITY_NAME;
    }

    public function getDefinitionClass(): string
    {
        return OrderDefinition::class;
    }
}
