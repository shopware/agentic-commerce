<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Migration\Migration1778146739AddSalesChannelTrackingCustomerPrivilege;

/**
 * @internal
 */
#[CoversClass(Migration1778146739AddSalesChannelTrackingCustomerPrivilege::class)]
class Migration1778146739AddSalesChannelTrackingCustomerPrivilegeTest extends TestCase
{
    public function testCreationTimestampMatchesClassName(): void
    {
        static::assertSame(
            1778146739,
            (new Migration1778146739AddSalesChannelTrackingCustomerPrivilege())->getCreationTimestamp(),
        );
    }

    public function testNewPrivilegesShape(): void
    {
        $privileges = Migration1778146739AddSalesChannelTrackingCustomerPrivilege::NEW_PRIVILEGES;

        static::assertArrayHasKey('customer.viewer', $privileges);
        static::assertContains('sales_channel_tracking_customer:read', $privileges['customer.viewer']);
    }

    public function testUpdateNoOpWhenNoRolesExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('iterateAssociative')->willReturn(new \ArrayIterator([]));
        $connection->expects(static::once())->method('beginTransaction');
        $connection->expects(static::once())->method('commit');
        $connection->expects(static::never())->method('update');

        (new Migration1778146739AddSalesChannelTrackingCustomerPrivilege())->update($connection);
    }

    public function testUpdateAddsPrivilegeToRoleThatHasCustomerViewer(): void
    {
        $existingPrivileges = ['customer.viewer', 'order.viewer'];
        $role = [
            'id' => 'role-id',
            'name' => 'Customer Viewer',
            'privileges' => json_encode($existingPrivileges, \JSON_THROW_ON_ERROR),
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('iterateAssociative')->willReturn(new \ArrayIterator([$role]));
        $connection->expects(static::once())->method('beginTransaction');
        $connection->expects(static::once())->method('commit');

        $connection->expects(static::once())
            ->method('update')
            ->with(
                'acl_role',
                static::callback(static function (array $updated): bool {
                    $privileges = json_decode($updated['privileges'], true, 512, \JSON_THROW_ON_ERROR);

                    return \in_array('customer.viewer', $privileges, true)
                        && \in_array('sales_channel_tracking_customer:read', $privileges, true);
                }),
                ['id' => 'role-id'],
            );

        (new Migration1778146739AddSalesChannelTrackingCustomerPrivilege())->update($connection);
    }

    public function testUpdateSkipsRoleWithoutCustomerViewer(): void
    {
        $role = [
            'id' => 'role-id',
            'name' => 'Order Viewer',
            'privileges' => json_encode(['order.viewer'], \JSON_THROW_ON_ERROR),
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('iterateAssociative')->willReturn(new \ArrayIterator([$role]));
        $connection->expects(static::once())->method('beginTransaction');
        $connection->expects(static::once())->method('commit');
        $connection->expects(static::never())->method('update');

        (new Migration1778146739AddSalesChannelTrackingCustomerPrivilege())->update($connection);
    }

    public function testUpdateDestructiveIsNoOp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(static::never())->method('executeStatement');
        $connection->expects(static::never())->method('iterateAssociative');
        $connection->expects(static::never())->method('update');

        (new Migration1778146739AddSalesChannelTrackingCustomerPrivilege())->updateDestructive($connection);
    }
}
