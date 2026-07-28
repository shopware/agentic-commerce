<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Ucp\Identity;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;

/**
 * The access-token lookup is the resource server's trust boundary, so it is
 * covered against a real database rather than a mocked connection.
 *
 * @internal
 */
final class DoctrineDbalUcpOAuthStoreAccessTokenTest extends TestCase
{
    private const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';
    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    private Connection $connection;

    private DoctrineDbalUcpOAuthStore $store;

    private string $salesChannelId;

    protected function setUp(): void
    {
        $this->connection = KernelLifecycleManager::getConnection();
        $this->store = new DoctrineDbalUcpOAuthStore($this->connection);
        $this->salesChannelId = $this->anySalesChannelId();

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    public function testItResolvesAnIssuedAccessTokenToItsSubjectAndScopes(): void
    {
        $tokenSet = $this->store->issueTokenSet($this->salesChannelId, $this->clientId(), 'customer-id', 'com.shopware.quote:manage dev.ucp.shopping.order:read');

        $token = $this->store->findAccessToken($tokenSet->accessToken, $this->salesChannelId);

        static::assertNotNull($token);
        static::assertSame('customer-id', $token->subject);
        static::assertSame($this->salesChannelId, $token->salesChannelId);
        static::assertTrue($token->hasScope('com.shopware.quote:manage'));
        static::assertTrue($token->hasScope('dev.ucp.shopping.order:read'));
        static::assertFalse($token->hasScope('dev.ucp.shopping.cart:manage'));
    }

    public function testItRejectsAnUnknownToken(): void
    {
        static::assertNull($this->store->findAccessToken('ucp_access_never_issued', $this->salesChannelId));
    }

    public function testItRejectsATokenFromAnotherSalesChannel(): void
    {
        $tokenSet = $this->store->issueTokenSet($this->salesChannelId, $this->clientId(), 'customer-id', 'com.shopware.quote:manage');

        static::assertNull($this->store->findAccessToken($tokenSet->accessToken, Uuid::randomHex()));
    }

    public function testItRejectsAnExpiredToken(): void
    {
        $tokenSet = $this->store->issueTokenSet($this->salesChannelId, $this->clientId(), 'customer-id', 'com.shopware.quote:manage');

        $this->connection->executeStatement(
            \sprintf('UPDATE `%s` SET expires_at = :expiresAt', self::ACCESS_TOKEN_TABLE),
            ['expiresAt' => time() - 1],
        );

        static::assertNull($this->store->findAccessToken($tokenSet->accessToken, $this->salesChannelId));
    }

    public function testItRejectsATokenWhoseRefreshFamilyWasRevoked(): void
    {
        $tokenSet = $this->store->issueTokenSet($this->salesChannelId, $this->clientId(), 'customer-id', 'com.shopware.quote:manage');

        // Revoking the link (or detecting refresh-token reuse) must stop the still
        // unexpired access token immediately.
        $this->connection->executeStatement(
            \sprintf('UPDATE `%s` SET revoked_at = :revokedAt', self::REFRESH_TOKEN_TABLE),
            ['revokedAt' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
        );

        static::assertNull($this->store->findAccessToken($tokenSet->accessToken, $this->salesChannelId));
    }

    private function clientId(): string
    {
        return 'https://agent.example/.well-known/ucp';
    }

    private function anySalesChannelId(): string
    {
        $id = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel LIMIT 1');

        static::assertIsString($id);

        return $id;
    }

    private function cleanUp(): void
    {
        $this->connection->executeStatement(\sprintf('DELETE FROM `%s` WHERE client_id = :clientId', self::ACCESS_TOKEN_TABLE), ['clientId' => $this->clientId()]);
        $this->connection->executeStatement(\sprintf('DELETE FROM `%s` WHERE client_id = :clientId', self::REFRESH_TOKEN_TABLE), ['clientId' => $this->clientId()]);
    }
}
