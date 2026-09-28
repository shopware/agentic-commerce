<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Swag\AgenticCommerce\Ucp\Identity\AbstractUcpOAuthScopeProvider;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;
use Swag\AgenticCommerce\Ucp\Identity\OAuthClientBindingValidator;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Swag\AgenticCommerce\Ucp\Identity\UcpOAuthScopeRegistry;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareIdentityLinkingAdapterTest extends TestCase
{
    private ShopwareIdentityLinkingAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ShopwareIdentityLinkingAdapter(
            $this->contextResolver(),
            new DoctrineDbalUcpOAuthStore($this->createMock(Connection::class)),
        );
    }

    #[Test]
    public function testAuthorizationCodeExchangeRequiresRedirectUriBeforeConsumingCode(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Redirect URI is required for authorization code exchange.');

        $this->adapter->issueToken(
            new OAuthTokenRequest(
                grantType: 'authorization_code',
                code: 'ucp_code_existing',
                codeVerifier: 'verifier',
            ),
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testAuthorizationCodeExchangeRequiresClientIdBeforeConsumingCode(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Missing OAuth client ID.');

        $this->adapter->issueToken(
            new OAuthTokenRequest(
                grantType: 'authorization_code',
                code: 'ucp_code_existing',
                codeVerifier: 'verifier',
                redirectUri: 'https://agent.example/callback',
            ),
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testRefreshTokenExchangeRequiresClientId(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Missing OAuth client ID.');

        $this->adapter->issueToken(
            new OAuthTokenRequest(
                grantType: 'refresh_token',
                refreshToken: 'ucp_refresh_existing',
            ),
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testTheOAuthMetadataAdvertisesCoreAndRegisteredScopes(): void
    {
        $adapter = new ShopwareIdentityLinkingAdapter(
            $this->contextResolver(),
            new DoctrineDbalUcpOAuthStore($this->createMock(Connection::class)),
            new OAuthClientBindingValidator(),
            new UcpOAuthScopeRegistry([new class extends AbstractUcpOAuthScopeProvider {
                public function getScopes(): array
                {
                    return ['com.shopware.quote:manage'];
                }
            }]),
        );

        static::assertSame(
            [
                'dev.ucp.shopping.cart:manage',
                'dev.ucp.shopping.order:read',
                'dev.ucp.shopping.order:manage',
                'com.shopware.quote:manage',
            ],
            $adapter->getMetadata(new RequestContext('shop.example'))->scopesSupported,
        );
    }

    private function contextResolver(): SalesChannelContextResolver
    {
        return new SalesChannelContextResolver(
            new SalesChannelDomainResolver($this->createMock(EntityRepository::class)),
            $this->createMock(SalesChannelContextServiceInterface::class),
            $this->createMock(SalesChannelContextPersister::class),
        );
    }
}
