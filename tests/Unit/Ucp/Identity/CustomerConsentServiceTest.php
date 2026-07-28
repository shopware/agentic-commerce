<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Identity\AuthorizationCodeIssuerInterface;
use Swag\AgenticCommerce\Ucp\Identity\Consent\CustomerConsentRequest;
use Swag\AgenticCommerce\Ucp\Identity\Consent\CustomerConsentService;
use Swag\AgenticCommerce\Ucp\Identity\OAuthClientBindingValidator;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Ucp\Sdk\Exception\OAuthException;

/** @internal */
#[CoversClass(CustomerConsentService::class)]
#[CoversClass(CustomerConsentRequest::class)]
#[CoversClass(OAuthClientBindingValidator::class)]
final class CustomerConsentServiceTest extends TestCase
{
    private const CLIENT_ID = 'https://agent.example.com/.well-known/ucp';
    private const REDIRECT_URI = 'https://agent.example.com/callback';
    private const SALES_CHANNEL_ID = 'sales-channel-id';

    #[Test]
    public function testItParsesAValidAuthorizationRequest(): void
    {
        $request = $this->service()->parse($this->parameters(), self::SALES_CHANNEL_ID);

        self::assertSame(self::CLIENT_ID, $request->clientId);
        self::assertSame(self::REDIRECT_URI, $request->redirectUri);
        self::assertSame([ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE], $request->scopes);
        self::assertSame('state-123', $request->state);
        // What a person can recognise on the consent page.
        self::assertSame('agent.example.com', $request->clientHost());
    }

    #[Test]
    public function testItRejectsAPlatformThatIsNotAllowlisted(): void
    {
        $service = $this->service(allowedPlatforms: ['other.example.com']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/is not allowed for this sales channel/');
        $service->parse($this->parameters(), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItRejectsAnEmptyPlatformAllowlist(): void
    {
        $service = $this->service(allowedPlatforms: []);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/No agent platform is allowed/');
        $service->parse($this->parameters(), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItRejectsARedirectUriFromAnotherOrigin(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/must use the signed platform profile origin/');
        $this->service()->parse(
            $this->parameters(['redirect_uri' => 'https://attacker.example/callback']),
            self::SALES_CHANNEL_ID,
        );
    }

    #[Test]
    public function testItRequiresPkce(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/code challenge is required/');
        $this->service()->parse($this->parameters(['code_challenge' => '']), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItRejectsAWeakerPkceMethod(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/Only PKCE S256/');
        $this->service()->parse($this->parameters(['code_challenge_method' => 'plain']), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItRejectsAMalformedCodeChallenge(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/base64url-encoded SHA-256/');
        $this->service()->parse($this->parameters(['code_challenge' => '<generated>']), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItRejectsAnUnsupportedScope(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/Unsupported OAuth scope/');
        $this->service()->parse($this->parameters(['scope' => 'com.shopware.everything']), self::SALES_CHANNEL_ID);
    }

    #[Test]
    public function testItFallsBackToAllSupportedScopes(): void
    {
        $request = $this->service()->parse($this->parameters(['scope' => '']), self::SALES_CHANNEL_ID);

        self::assertSame(ShopwareIdentityLinkingAdapter::SUPPORTED_SCOPES, $request->scopes);
    }

    #[Test]
    public function testItDescribesScopesInHumanTerms(): void
    {
        $request = $this->service()->parse($this->parameters(), self::SALES_CHANNEL_ID);

        self::assertSame([[
            'scope' => ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE,
            'description' => 'Request, negotiate and accept quotes on your behalf',
        ]], $this->service()->describeScopes($request));
    }

    #[Test]
    public function testApprovalRedirectsBackWithCodeAndState(): void
    {
        $store = $this->createMock(AuthorizationCodeIssuerInterface::class);
        $store->expects(self::once())
            ->method('issueAuthorizationCode')
            ->with(self::SALES_CHANNEL_ID, self::CLIENT_ID, self::REDIRECT_URI, 'customer-id', ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE)
            ->willReturn('ucp_code_abc');

        $service = $this->service(store: $store);
        $request = $service->parse($this->parameters(), self::SALES_CHANNEL_ID);

        $url = $service->approve($request, 'customer-id', self::SALES_CHANNEL_ID, 'https://shop.example/');

        self::assertSame(
            'https://agent.example.com/callback?code=ucp_code_abc&state=state-123&iss=https%3A%2F%2Fshop.example',
            $url,
        );
    }

    #[Test]
    public function testDenialReportsAccessDeniedToTheClient(): void
    {
        $service = $this->service();
        $request = $service->parse($this->parameters(), self::SALES_CHANNEL_ID);

        $url = $service->deny($request);

        self::assertStringContainsString('error=access_denied', $url);
        self::assertStringContainsString('state=state-123', $url);
        self::assertStringNotContainsString('code=', $url);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function parameters(array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE,
            'state' => 'state-123',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ], $overrides);
    }

    /**
     * @param list<string> $allowedPlatforms
     */
    private function service(
        array $allowedPlatforms = ['agent.example.com'],
        ?AuthorizationCodeIssuerInterface $store = null,
    ): CustomerConsentService {
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'SwagAgenticCommerce.config.active' => true,
                'SwagAgenticCommerce.config.platformAllowlist' => $allowedPlatforms,
                default => null,
            },
        );

        return new CustomerConsentService(
            $store ?? $this->createMock(AuthorizationCodeIssuerInterface::class),
            new UcpConfigService($this->createMock(UcpConfigRepositoryInterface::class), $legacyStore),
        );
    }
}
