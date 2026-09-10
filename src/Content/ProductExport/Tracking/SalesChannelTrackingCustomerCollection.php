<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\Log\Package;

/**
 * @extends EntityCollection<SalesChannelTrackingCustomerEntity>
 *
 * @internal
 */
#[Package('after-sales')]
class SalesChannelTrackingCustomerCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesChannelTrackingCustomerEntity::class;
    }
}
