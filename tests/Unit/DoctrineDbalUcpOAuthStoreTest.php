<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;
use Swag\AgenticCommerce\Ucp\Identity\OAuthTokenSet;

/** @internal */
final class DoctrineDbalUcpOAuthStoreTest extends TestCase
{
    #[Test]
    public function testItRotatesRefreshTokensOnUse(): void
    {
        $statements = [];
        $inserts = [];
        $connection = $this->connection($statements, $inserts);
        $connection->method('fetchAssociative')->willReturn([
            'sales_channel_id' => '00000000000000000000000000000001',
            'client_id' => 'https://agent.example/profile',
            'subject' => 'customer-id',
            'scope' => 'dev.ucp.shopping.cart:manage',
            'expires_at' => time() + 60,
            'revoked_at' => null,
        ]);

        $store = new DoctrineDbalUcpOAuthStore($connection);

        $tokenSet = $store->refreshTokenSet('ucp_refresh_existing', 'https://agent.example/profile', '00000000000000000000000000000001');

        self::assertInstanceOf(OAuthTokenSet::class, $tokenSet);
        self::assertStringStartsWith('ucp_access_', $tokenSet->accessToken);
        self::assertStringStartsWith('ucp_refresh_', $tokenSet->refreshToken);
        self::assertCount(1, $this->updatesContaining($statements, 'WHERE token_hash = :tokenHash'));
        self::assertSame(
            ['swag_agentic_commerce_ucp_oauth_refresh_token', 'swag_agentic_commerce_ucp_oauth_access_token'],
            array_column($inserts, 'table'),
        );
    }

    #[Test]
    public function testItRevokesRefreshTokenFamilyOnReuse(): void
    {
        $statements = [];
        $inserts = [];
        $connection = $this->connection($statements, $inserts);
        $connection->method('fetchAssociative')->willReturn([
            'sales_channel_id' => '00000000000000000000000000000001',
            'client_id' => 'https://agent.example/profile',
            'subject' => 'customer-id',
            'scope' => 'dev.ucp.shopping.cart:manage',
            'expires_at' => time() + 60,
            'revoked_at' => '2026-01-01 00:00:00.000',
        ]);

        $store = new DoctrineDbalUcpOAuthStore($connection);

        self::assertNull($store->refreshTokenSet('ucp_refresh_reused', 'https://agent.example/profile', '00000000000000000000000000000001'));
        self::assertSame([], $inserts);
        self::assertCount(1, $this->updatesContaining($statements, 'WHERE sales_channel_id = :salesChannelId AND client_id = :clientId AND subject = :subject'));
    }

    #[Test]
    public function testAuthorizationCodeHashCollisionDoesNotOverwriteExistingCode(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('insert')->willThrowException($this->uniqueConstraintViolation());
        $connection->expects(static::never())->method('update');

        $store = new DoctrineDbalUcpOAuthStore($connection);

        $this->expectException(UniqueConstraintViolationException::class);

        $store->saveAuthorizationCode(
            '00000000000000000000000000000001',
            'ucp_code_collision',
            'https://agent.example/profile',
            'https://agent.example/callback',
            'customer-id',
            'dev.ucp.shopping.cart:manage',
            'challenge',
            'S256',
        );
    }

    #[Test]
    public function testItDeletesExpiredAndConsumedTokens(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return match (true) {
                    str_contains($sql, 'swag_agentic_commerce_ucp_oauth_code') => 2,
                    str_contains($sql, 'swag_agentic_commerce_ucp_oauth_access_token') => 3,
                    str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token') => 1,
                    default => 0,
                };
            },
        );

        $store = new DoctrineDbalUcpOAuthStore($connection);

        self::assertSame(6, $store->deleteExpiredTokens());

        $deletes = $this->deletesContaining($statements, '');
        self::assertSame(
            [
                'swag_agentic_commerce_ucp_oauth_code',
                'swag_agentic_commerce_ucp_oauth_access_token',
                'swag_agentic_commerce_ucp_oauth_refresh_token',
            ],
            array_map(
                static function (array $statement): string {
                    self::assertIsInt($statement['params']['now'] ?? null, 'each DELETE filters on the current time');

                    preg_match('/DELETE FROM `([^`]+)`/', $statement['sql'], $matches);

                    return $matches[1] ?? '';
                },
                $deletes,
            ),
        );

        self::assertCount(1, $this->deletesContaining($statements, 'consumed_at IS NOT NULL'));
        self::assertStringContainsString('consumed_at IS NOT NULL', $deletes[0]['sql']);

        $refreshDelete = $this->deletesContaining($statements, 'swag_agentic_commerce_ucp_oauth_refresh_token');
        self::assertCount(1, $refreshDelete);
        self::assertStringNotContainsString('revoked_at', $refreshDelete[0]['sql'], 'revoked-but-unexpired refresh tokens must be retained for reuse detection');
    }

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

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     * @param list<array{table: string, data: array<string, mixed>}> $inserts
     */
    private function connection(array &$statements, array &$inserts): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return str_starts_with(ltrim($sql), 'UPDATE') ? 1 : 0;
            },
        );
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data = [], array $types = []) use (&$inserts): int {
                $inserts[] = ['table' => $table, 'data' => $data];

                return 1;
            },
        );

        return $connection;
    }

    private function uniqueConstraintViolation(): UniqueConstraintViolationException
    {
        /** @var UniqueConstraintViolationException $exception */
        $exception = (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();

        return $exception;
    }

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     *
     * @return list<array{sql: string, params: array<string, mixed>}>
     */
    private function updatesContaining(array $statements, string $needle): array
    {
        return array_values(array_filter(
            $statements,
            static fn (array $statement): bool => str_starts_with(ltrim($statement['sql']), 'UPDATE')
                && str_contains($statement['sql'], $needle),
        ));
    }

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     *
     * @return list<array{sql: string, params: array<string, mixed>}>
     */
    private function deletesContaining(array $statements, string $needle): array
    {
        return array_values(array_filter(
            $statements,
            static fn (array $statement): bool => str_starts_with(ltrim($statement['sql']), 'DELETE')
                && str_contains($statement['sql'], $needle),
        ));
    }
}
