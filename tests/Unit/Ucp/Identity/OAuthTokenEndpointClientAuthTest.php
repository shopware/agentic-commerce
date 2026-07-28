<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\OAuthClientBindingValidator;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * The token endpoint must accept what its own metadata advertises
 * (`token_endpoint_auth_methods_supported: ["none"]`), i.e. a public client
 * authenticated by PKCE - not a proprietary signed-profile requirement.
 *
 * @internal
 */
#[CoversClass(OAuthClientBindingValidator::class)]
final class OAuthTokenEndpointClientAuthTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example.com/.well-known/ucp';

    #[Test]
    public function testItAcceptsAPublicClientWithoutAnySignedProfile(): void
    {
        $this->validator()->assertTokenEndpointClient(self::CLIENT_ID, new RequestContext('shop.example'), ['agent.example.com']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function testItStillRequiresTheClientHostToBeAllowlisted(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/is not allowed for this sales channel/');
        $this->validator()->assertTokenEndpointClient(self::CLIENT_ID, new RequestContext('shop.example'), ['other.example.com']);
    }

    #[Test]
    public function testItRejectsAClientIdThatIsNotHttps(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/HTTPS platform profile URI/');
        $this->validator()->assertTokenEndpointClient('http://agent.example.com/profile', new RequestContext('shop.example'), ['agent.example.com']);
    }

    #[Test]
    public function testItKeepsTheStrongerBindingWhenAProfileIsPresented(): void
    {
        $context = new RequestContext(
            'shop.example',
            platformProfileUri: 'https://someone-else.example/.well-known/ucp',
            platformProfile: new PlatformProfile('2026-04-08', [], [], []),
            signatureVerified: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/must match the presented platform profile URI/');
        $this->validator()->assertTokenEndpointClient(self::CLIENT_ID, $context, ['agent.example.com']);
    }

    #[Test]
    public function testItPublishesTheStandardUcpCheckoutSessionScope(): void
    {
        self::assertContains('ucp:scopes:checkout_session', ShopwareIdentityLinkingAdapter::SUPPORTED_SCOPES);
        self::assertSame('ucp:scopes:checkout_session', ShopwareIdentityLinkingAdapter::SCOPE_CHECKOUT_SESSION);
    }

    private function validator(): OAuthClientBindingValidator
    {
        return new OAuthClientBindingValidator();
    }
}
