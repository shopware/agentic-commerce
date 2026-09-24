<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * A `product` definition without the Dynamic Access extension.
 *
 * @internal
 */
class PlainProductDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'product';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([(new IdField('id', 'id'))->addFlags(new PrimaryKey())]);
    }
}
