<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;

/**
 * Builds the SDK's storage connection from Shopware's connection parameters, so it carries the driver options
 * Shopware derives from `DATABASE_SSL_*`.
 *
 * The connection is always a separate, non-persistent connection to the primary. SDK storage holds security state
 * (signature nonces, idempotency claims, signing keys) that must commit on its own, independent of any transaction
 * Shopware has open, and must never be read from a lagging replica. A persistent PDO handle with the same DSN and
 * user would be Shopware's own physical link, so persistence is switched off.
 *
 * DBAL 3 keeps the original `url` next to the parameters parsed from it and would parse it again, letting query
 * options such as `persistent` override the settings below, so it is dropped.
 *
 * @internal
 */
final class SdkStorageConnectionFactory
{
    public static function create(Connection $shopwareConnection): Connection
    {
        $params = $shopwareConnection->getParams();
        // @phpstan-ignore unset.offset (DBAL 4 no longer knows `url`; DBAL 3 still returns it)
        unset($params['url'], $params['primary'], $params['replica'], $params['keepReplica']);

        if (is_a($params['wrapperClass'] ?? Connection::class, PrimaryReadReplicaConnection::class, true)) {
            unset($params['wrapperClass']);
        }

        $params['driverOptions'][\PDO::ATTR_PERSISTENT] = false;
        $params['persistent'] = false;

        return DriverManager::getConnection($params, $shopwareConnection->getConfiguration());
    }
}
