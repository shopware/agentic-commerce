<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigSchema;
use Swag\AgenticCommerce\Ucp\Identity\UcpOAuthSchema;

final class Migration1780328112CreateUcpConfigTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1780328112;
    }

    public function update(Connection $connection): void
    {
        // The historical class name only mentions config, but this migration is
        // the first UCP state migration and owns both config and OAuth tables.
        UcpConfigSchema::ensure($connection);
        UcpOAuthSchema::ensure($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Keep merchant configuration and OAuth state unless a future explicit
        // uninstall/data-retention policy decides to purge it.
    }
}
