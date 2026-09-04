<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;

/**
 * Adds the `salesChannelTracking` association on the `customer` entity. The
 * cascade-delete flag mirrors the behaviour of the native extension so deleting
 * a customer removes the tracking row before the FK constraint kicks in.
 *
 * @internal
 */
#[Package('after-sales')]
class CustomerSalesChannelTrackingExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'salesChannelTracking',
                'id',
                'customer_id',
                SalesChannelTrackingCustomerDefinition::class,
                false,
            ))->addFlags(new CascadeDelete()),
        );
    }

    public function getEntityName(): string
    {
        return CustomerDefinition::ENTITY_NAME;
    }

    public function getDefinitionClass(): string
    {
        return CustomerDefinition::class;
    }
}
