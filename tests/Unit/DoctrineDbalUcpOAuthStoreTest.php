<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;

/** @internal */
final class DoctrineDbalUcpOAuthStoreTest extends TestCase
{
    #[Test]
    public function testDeleteExpiredTokensDrainsEachTableInCappedBatches(): void
    {
        $connection = $this->createMock(Connection::class);

        // Three tables are purged. The first returns a full batch (1000) and must be queried
        // again until it clears fewer rows than the batch size; the other two drain in one pass.
        // Total executeStatement calls: 2 + 1 + 1 = 4, summing to 1000 + 5 + 3 + 2 = 1010.
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(1000, 5, 3, 2);

        $store = new DoctrineDbalUcpOAuthStore($connection);

        self::assertSame(1010, $store->deleteExpiredTokens());
    }
}
