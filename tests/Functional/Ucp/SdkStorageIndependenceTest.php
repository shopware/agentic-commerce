<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;

/**
 * SDK security state must commit on its own: a signature checked during a request stays used even when Shopware
 * rolls back its own transaction. These tests deliberately run without a wrapping test transaction.
 *
 * @internal
 */
final class SdkStorageIndependenceTest extends TestCase
{
    use KernelTestBehaviour;

    public function testARecordedNonceSurvivesAShopwareRollback(): void
    {
        $nonces = static::getContainer()->get(SignatureNonceRepositoryInterface::class);
        static::assertInstanceOf(SignatureNonceRepositoryInterface::class, $nonces);
        $shopwareConnection = static::getContainer()->get(Connection::class);
        $scope = 'sdk-storage-independence-'.bin2hex(random_bytes(8));

        try {
            $shopwareConnection->beginTransaction();
            static::assertTrue($nonces->saveIfNew($scope, 'default', 'signature-hash'));
            $shopwareConnection->rollBack();

            static::assertFalse($nonces->saveIfNew($scope, 'default', 'signature-hash'), 'A replayed signature must stay rejected');
        } finally {
            if ($shopwareConnection->isTransactionActive()) {
                $shopwareConnection->rollBack();
            }
            $shopwareConnection->delete('ucp_signature_nonces', ['scope' => $scope]);
        }
    }

    public function testAKernelResetClosesTheSdkConnection(): void
    {
        $nonces = static::getContainer()->get(SignatureNonceRepositoryInterface::class);
        static::assertIsObject($nonces);
        $sdkConnection = (new \ReflectionProperty($nonces, 'connection'))->getValue($nonces);
        static::assertInstanceOf(Connection::class, $sdkConnection);

        $sdkConnection->executeQuery('SELECT 1');
        static::assertTrue($sdkConnection->isConnected());

        static::getContainer()->get('services_resetter')->reset();

        static::assertFalse($sdkConnection->isConnected());
        static::assertSame('1', (string) $sdkConnection->fetchOne('SELECT 1'), 'The connection must reopen lazily');
    }
}
