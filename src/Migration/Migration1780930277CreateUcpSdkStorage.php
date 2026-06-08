<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Swag\AgenticCommerce\Exception\SdkNotAvailableException;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class Migration1780930277CreateUcpSdkStorage extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1780930277;
    }

    public function update(Connection $connection): void
    {
        $this->loadBundledSdkAutoload();

        if (!class_exists(SchemaBootstrapper::class)) {
            throw SdkNotAvailableException::bundleCouldNotBeLoaded();
        }

        (new SchemaBootstrapper($connection))->ensureSchema();
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function loadBundledSdkAutoload(): void
    {
        $autoloadPath = __DIR__.'/../../vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }
    }
}
