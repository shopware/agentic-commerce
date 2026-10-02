<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;

/**
 * A `product` definition carrying the field Dynamic Access adds.
 *
 * @internal
 */
final class DynamicAccessProductDefinition extends PlainProductDefinition
{
    protected function defineFields(): FieldCollection
    {
        $fields = parent::defineFields();
        $fields->add(new JsonField(TestDataEnvironment::DYNAMIC_ACCESS_PRODUCT_FIELD, TestDataEnvironment::DYNAMIC_ACCESS_PRODUCT_FIELD));

        return $fields;
    }
}
