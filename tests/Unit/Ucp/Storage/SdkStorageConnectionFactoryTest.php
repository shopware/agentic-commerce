<?php

declare(strict_types=1);

/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Storage\SdkStorageConnectionFactory;

/**
 * Connections are never opened here: DBAL connects lazily, and these tests only inspect which connection the SDK
 * gets and its parameters. The TLS handshake itself is covered by the secure-transport reproduction.
 *
 * @internal
 */
#[CoversClass(SdkStorageConnectionFactory::class)]
class SdkStorageConnectionFactoryTest extends TestCase
{
    private const SSL_CA = 1009;
    private const SSL_CERT = 1008;
    private const SSL_KEY = 1007;
    private const SSL_VERIFY_SERVER_CERT = 1014;

    public function testItBuildsASeparateConnectionWithTheShopwareTlsOptions(): void
    {
        $shopware = DriverManager::getConnection($this->shopwareParams(), new Configuration());

        $sdk = SdkStorageConnectionFactory::create($shopware);

        static::assertNotSame($shopware, $sdk);
        static::assertSame($shopware->getConfiguration(), $sdk->getConfiguration());

        $sdkParams = $sdk->getParams();
        static::assertSame('db.example.test', $sdkParams['host'] ?? null);
        static::assertSame('utf8mb4', $sdkParams['charset'] ?? null);

        $driverOptions = $sdkParams['driverOptions'] ?? [];
        foreach ($this->shopwareParams()['driverOptions'] as $option => $value) {
            static::assertSame($value, $driverOptions[$option] ?? null);
        }
    }

    public function testItNeverReusesAPersistentConnection(): void
    {
        $params = $this->shopwareParams();
        $params['persistent'] = true;
        $params['driverOptions'][\PDO::ATTR_PERSISTENT] = true;
        $shopware = DriverManager::getConnection($params, new Configuration());

        $sdkParams = SdkStorageConnectionFactory::create($shopware)->getParams();

        static::assertFalse($sdkParams['persistent'] ?? null);
        static::assertFalse($sdkParams['driverOptions'][\PDO::ATTR_PERSISTENT] ?? null);
    }

    /**
     * Shopware 6.5 and 6.6 hand DBAL 3 a `url`, which DBAL 3 keeps in `getParams()` next to the values parsed from it.
     */
    public function testItDoesNotLetTheDatabaseUrlReenablePersistence(): void
    {
        $params = $this->shopwareParams();
        $params['url'] = 'mysql://user:pass@db.example.test:3306/shopware?persistent=1';
        $params['persistent'] = '1';
        $shopware = $this->createStub(Connection::class);
        $shopware->method('getParams')->willReturn($params);
        $shopware->method('getConfiguration')->willReturn(new Configuration());

        $sdkParams = SdkStorageConnectionFactory::create($shopware)->getParams();

        static::assertFalse($sdkParams['persistent'] ?? null);
        static::assertFalse($sdkParams['driverOptions'][\PDO::ATTR_PERSISTENT] ?? null);
        static::assertSame('/certs/ca.pem', $sdkParams['driverOptions'][self::SSL_CA] ?? null);
        static::assertArrayNotHasKey('url', $sdkParams);
    }

    public function testItConnectsOnlyToThePrimaryWhenReplicasAreConfigured(): void
    {
        $params = $this->shopwareParams();
        $params['wrapperClass'] = PrimaryReadReplicaConnection::class;
        $params['keepReplica'] = true;
        $params['primary'] = $this->shopwareParams();
        $params['replica'] = [[
            'host' => 'replica.example.test',
            'driverOptions' => $this->shopwareParams()['driverOptions'],
        ]];
        $shopware = DriverManager::getConnection($params, new Configuration());
        static::assertInstanceOf(PrimaryReadReplicaConnection::class, $shopware);

        $sdk = SdkStorageConnectionFactory::create($shopware);

        static::assertNotInstanceOf(PrimaryReadReplicaConnection::class, $sdk);
        $sdkParams = $sdk->getParams();
        static::assertSame('db.example.test', $sdkParams['host'] ?? null);
        static::assertSame('utf8mb4', $sdkParams['charset'] ?? null);
        static::assertSame('/certs/ca.pem', $sdkParams['driverOptions'][self::SSL_CA] ?? null);
        static::assertArrayNotHasKey('primary', $sdkParams);
        static::assertArrayNotHasKey('replica', $sdkParams);
        static::assertArrayNotHasKey('keepReplica', $sdkParams);
        static::assertArrayNotHasKey('wrapperClass', $sdkParams);
    }

    /**
     * @return array{driver: 'pdo_mysql', host: string, port: int, user: string, password: string, dbname: string, charset: string, driverOptions: array<int, mixed>}
     */
    private function shopwareParams(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => 'db.example.test',
            'port' => 3306,
            'user' => 'user',
            'password' => 'pass',
            'dbname' => 'shopware',
            'charset' => 'utf8mb4',
            'driverOptions' => [
                self::SSL_CA => '/certs/ca.pem',
                self::SSL_CERT => '/certs/client-cert.pem',
                self::SSL_KEY => '/certs/client-key.pem',
                self::SSL_VERIFY_SERVER_CERT => false,
            ],
        ];
    }
}
