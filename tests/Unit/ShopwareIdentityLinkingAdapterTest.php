<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareIdentityLinkingAdapterTest extends TestCase
{
    #[Test]
    public function testAuthorizationCodeExchangeRequiresRedirectUriBeforeConsumingCode(): void
    {
        $adapter = new ShopwareIdentityLinkingAdapter(
            $this->uninitialized(SalesChannelContextResolver::class),
            $this->uninitialized(DoctrineDbalUcpOAuthStore::class),
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Redirect URI is required for authorization code exchange.');

        $adapter->issueToken(
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
        $adapter = new ShopwareIdentityLinkingAdapter(
            $this->uninitialized(SalesChannelContextResolver::class),
            $this->uninitialized(DoctrineDbalUcpOAuthStore::class),
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Missing OAuth client ID.');

        $adapter->issueToken(
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
        $adapter = new ShopwareIdentityLinkingAdapter(
            $this->uninitialized(SalesChannelContextResolver::class),
            $this->uninitialized(DoctrineDbalUcpOAuthStore::class),
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Missing OAuth client ID.');

        $adapter->issueToken(
            new OAuthTokenRequest(
                grantType: 'refresh_token',
                refreshToken: 'ucp_refresh_existing',
            ),
            new RequestContext('shop.example'),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
