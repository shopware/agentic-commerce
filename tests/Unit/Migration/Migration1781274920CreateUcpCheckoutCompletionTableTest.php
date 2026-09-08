<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Migration\Migration1781274920CreateUcpCheckoutCompletionTable;

/**
 * @internal
 */
#[CoversClass(Migration1781274920CreateUcpCheckoutCompletionTable::class)]
final class Migration1781274920CreateUcpCheckoutCompletionTableTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(
            1781274920,
            (new Migration1781274920CreateUcpCheckoutCompletionTable())->getCreationTimestamp(),
        );
    }

    public function testUpdateCreatesCheckoutCompletionTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::once())
            ->method('executeStatement')
            ->with(static::callback(static function (string $sql): bool {
                static::assertStringContainsString('CREATE TABLE IF NOT EXISTS `swag_agentic_commerce_ucp_checkout_completion`', $sql);
                static::assertStringContainsString('PRIMARY KEY (`checkout_id`)', $sql);
                static::assertStringNotContainsString('`sales_channel_id`', $sql);
                static::assertStringNotContainsString('`status`', $sql);
                static::assertStringContainsString('`order_id` BINARY(16) NOT NULL', $sql);
                static::assertStringContainsString('`order_version_id` BINARY(16) NOT NULL', $sql);
                static::assertStringContainsString('CONSTRAINT `fk.sac_ucp_checkout_completion.order_id`', $sql);
                static::assertStringContainsString('FOREIGN KEY (`order_id`, `order_version_id`) REFERENCES `order` (`id`, `version_id`)', $sql);

                return true;
            }));

        (new Migration1781274920CreateUcpCheckoutCompletionTable())->update($connection);
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');

        (new Migration1781274920CreateUcpCheckoutCompletionTable())->updateDestructive($connection);
    }
}
