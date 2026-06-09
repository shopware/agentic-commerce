<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds the `provider` column to the core `product_export` table so
 * that agentic commerce providers (for example OpenAI) can be selected
 * per product export. Mirrors the native column in Shopware 6.7.10+.
 *
 * @internal
 */
class Migration1773824493AddProviderColumnToProductExport extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1773824493;
    }

    public function update(Connection $connection): void
    {
        if ($this->coreShipsAgenticCommerce()) {
            return;
        }

        if ($this->providerColumnExists($connection)) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `product_export` ADD COLUMN `provider` VARCHAR(255) NULL DEFAULT NULL'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function coreShipsAgenticCommerce(): bool
    {
        return \defined('Shopware\\Core\\Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE');
    }

    private function providerColumnExists(Connection $connection): bool
    {
        $exists = $connection->fetchOne(
            'SHOW COLUMNS FROM `product_export` LIKE :column',
            ['column' => 'provider']
        );

        return false !== $exists;
    }
}
