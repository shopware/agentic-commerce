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
use Swag\AgenticCommerce\TestData\TestDataEnvironment;

/**
 * Stands in for Shopware Commercial's `bundle_item` definition.
 *
 * @internal
 */
final class BundleItemDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return TestDataEnvironment::BUNDLE_ITEM_ENTITY;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([(new IdField('id', 'id'))->addFlags(new PrimaryKey())]);
    }
}
