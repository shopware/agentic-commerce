<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\Identity\CleanupExpiredOAuthTokensTask;
use Swag\AgenticCommerce\Ucp\Identity\CleanupExpiredOAuthTokensTaskHandler;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;

/** @internal */
final class CleanupExpiredOAuthTokensTaskHandlerTest extends TestCase
{
    #[Test]
    public function testTaskRunsDailyAndReschedulesOnFailure(): void
    {
        self::assertSame('swag_agentic_commerce.ucp_oauth_token.cleanup', CleanupExpiredOAuthTokensTask::getTaskName());
        self::assertSame(86400, CleanupExpiredOAuthTokensTask::getDefaultInterval());
        self::assertTrue(CleanupExpiredOAuthTokensTask::shouldRescheduleOnFailure());
    }

    #[Test]
    public function testRunPurgesExpiredTokens(): void
    {
        $tables = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::exactly(3))->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$tables): int {
                preg_match('/DELETE FROM `([^`]+)`/', $sql, $matches);
                $tables[] = $matches[1] ?? '';

                return 0;
            },
        );

        $handler = new CleanupExpiredOAuthTokensTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new DoctrineDbalUcpOAuthStore($connection),
        );

        $handler->run();

        self::assertSame(
            [
                'swag_agentic_commerce_ucp_oauth_code',
                'swag_agentic_commerce_ucp_oauth_access_token',
                'swag_agentic_commerce_ucp_oauth_refresh_token',
            ],
            $tables,
        );
    }
}
