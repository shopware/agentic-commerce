<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport;

use Shopware\Core\Content\ProductExport\ProductExportHydrator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;

/**
 * @internal
 */
class AgenticProductExportHydrator extends ProductExportHydrator
{
    /**
     * @param array<string> $row
     */
    protected function assign(EntityDefinition $definition, Entity $entity, string $root, array $row, Context $context): Entity
    {
        $entity = parent::assign($definition, $entity, $root, $row, $context);

        if (\array_key_exists($root.'.provider', $row)) {
            $entity->assign(['provider' => $row[$root.'.provider']]);
        }

        return $entity;
    }
}
