<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigSchema;
use Swag\AgenticCommerce\Ucp\Identity\UcpOAuthSchema;

final class Migration20260601091828CreateUcpConfigTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 20260601091828;
    }

    public function update(Connection $connection): void
    {
        UcpConfigSchema::ensure($connection);
        UcpOAuthSchema::ensure($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
