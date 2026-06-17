<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\OAuthClientBindingValidator;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class OAuthClientBindingValidatorTest extends TestCase
{
    #[Test]
    public function testItRejectsUnsignedPlatformProfileContext(): void
    {
        $this->expectExceptionObject(new OAuthException('UCP identity linking requires a signed platform profile request.'));

        (new OAuthClientBindingValidator())->assertClientId(
            'https://agent.example/profile.json',
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testItRejectsClientIdDifferentFromSignedPlatformProfile(): void
    {
        $this->expectExceptionObject(new OAuthException('OAuth client ID must match the signed platform profile URI.'));

        (new OAuthClientBindingValidator())->assertClientId(
            'https://other.example/profile.json',
            $this->signedContext(),
        );
    }

    #[Test]
    public function testItRejectsRedirectUriOutsideTheSignedProfileOrigin(): void
    {
        $this->expectExceptionObject(new OAuthException('OAuth redirect URI must use the signed platform profile origin.'));

        (new OAuthClientBindingValidator())->assertRedirectUri(
            'https://evil.example/callback',
            'https://agent.example/profile.json',
        );
    }

    #[Test]
    public function testItAllowsRedirectUriOnTheSignedProfileOrigin(): void
    {
        (new OAuthClientBindingValidator())->assertRedirectUri(
            'https://agent.example/callback',
            'https://agent.example/profile.json',
        );

        $this->addToAssertionCount(1);
    }

    private function signedContext(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            platformProfileUri: 'https://agent.example/profile.json',
            platformProfile: new PlatformProfile('2026-04-08', [], [], []),
            signatureVerified: true,
        );
    }
}
