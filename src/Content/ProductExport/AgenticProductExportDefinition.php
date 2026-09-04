<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport;

use Shopware\Core\Content\ProductExport\ProductExportDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\Log\Package;

/**
 * Adds the `provider` field to {@see ProductExportDefinition} so the DAL of
 * Shopware 6.7.0–6.7.9.x persists and reads the value the admin sets via the
 * agentic commerce template selection. The matching DB column is created by
 * {@see \Swag\AgenticCommerce\Migration\Migration1773824493AddProviderColumnToProductExport}.
 *
 * The field shape is identical to the native definition in 6.7.10+. Together
 * with the non-destructive migration this guarantees that uninstalling the
 * plugin while keeping user data leaves the column populated and ready for
 * the core implementation to take over.
 *
 * @internal
 */
#[Package('discovery')]
class AgenticProductExportDefinition extends ProductExportDefinition
{
    public function getHydratorClass(): string
    {
        return AgenticProductExportHydrator::class;
    }

    protected function defineFields(): FieldCollection
    {
        $fields = parent::defineFields();

        $fields->add(new StringField('provider', 'provider'));

        return $fields;
    }
}
