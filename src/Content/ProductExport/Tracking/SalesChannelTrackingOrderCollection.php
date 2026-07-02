<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Tracking;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<SalesChannelTrackingOrderEntity>
 *
 * @internal
 */
class SalesChannelTrackingOrderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesChannelTrackingOrderEntity::class;
    }
}
