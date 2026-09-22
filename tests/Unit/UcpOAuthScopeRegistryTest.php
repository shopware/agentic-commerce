<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Identity\AbstractUcpOAuthScopeProvider;
use Swag\AgenticCommerce\Ucp\Identity\UcpOAuthScopeRegistry;
use Ucp\Sdk\Exception\OAuthException;

/** @internal */
final class UcpOAuthScopeRegistryTest extends TestCase
{
    private const CORE_SCOPES = [
        'dev.ucp.shopping.cart:manage',
        'dev.ucp.shopping.order:read',
        'dev.ucp.shopping.order:manage',
    ];

    #[Test]
    public function testCoreScopesAreSupportedWithoutAnyProvider(): void
    {
        static::assertSame(self::CORE_SCOPES, (new UcpOAuthScopeRegistry())->supported());
    }

    #[Test]
    public function testCoreScopesStayGrantableAlongsideARegisteredOne(): void
    {
        $registry = $this->registryWith('com.vendor.scope:manage');

        static::assertSame(
            implode(' ', self::CORE_SCOPES),
            $registry->normalize(implode(' ', self::CORE_SCOPES)),
        );
    }

    #[Test]
    public function testARegisteredScopeIsAdvertisedAndAccepted(): void
    {
        $registry = $this->registryWith('com.vendor.scope:manage');

        static::assertContains('com.vendor.scope:manage', $registry->supported());
        static::assertSame('com.vendor.scope:manage', $registry->normalize('com.vendor.scope:manage'));
    }

    #[Test]
    public function testAnUnregisteredScopeIsRejectedAndTheMessageNamesTheSupportedSet(): void
    {
        $registry = $this->registryWith('com.vendor.scope:manage');

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unsupported OAuth scope "com.vendor.other:manage". Supported scopes: dev.ucp.shopping.cart:manage dev.ucp.shopping.order:read dev.ucp.shopping.order:manage com.vendor.scope:manage.');

        $registry->normalize('com.vendor.other:manage');
    }

    #[Test]
    public function testAnEmptyRequestExpandsToEverySupportedScope(): void
    {
        $registry = $this->registryWith('com.vendor.scope:manage');

        static::assertSame(
            'dev.ucp.shopping.cart:manage dev.ucp.shopping.order:read dev.ucp.shopping.order:manage com.vendor.scope:manage',
            $registry->normalize('  '),
        );
    }

    #[Test]
    public function testAScopeRegisteredTwiceIsAdvertisedOnce(): void
    {
        $registry = $this->registryWith('com.vendor.scope:manage', 'com.vendor.scope:manage');

        static::assertSame([...self::CORE_SCOPES, 'com.vendor.scope:manage'], $registry->supported());
    }

    private function registryWith(string ...$scopes): UcpOAuthScopeRegistry
    {
        $providers = array_map(
            static fn (string $scope): AbstractUcpOAuthScopeProvider => new class($scope) extends AbstractUcpOAuthScopeProvider {
                public function __construct(private readonly string $scope)
                {
                }

                public function getScopes(): array
                {
                    return [$this->scope];
                }
            },
            $scopes,
        );

        return new UcpOAuthScopeRegistry($providers);
    }
}
