<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class CheckoutWebhookUrlGuardTest extends TestCase
{
    #[Test]
    public function testItNormalizesWebhookAndAllowlistHosts(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->uninitialized(SalesChannelViewProvider::class));

        $guard->assertAllowed(
            'https://Agent.Example./webhook',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
        );

        self::addToAssertionCount(1);
    }

    #[Test]
    public function testItRejectsWebhookUrlsWithoutHttpHost(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->uninitialized(SalesChannelViewProvider::class));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override URLs must use http or https and include a host.');

        $guard->assertAllowed(
            'file:///etc/passwd',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
        );
    }

    #[Test]
    public function testItRejectsWebhookHostsOutsideAllowlist(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->uninitialized(SalesChannelViewProvider::class));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override host "evil.example" is not permitted.');

        $guard->assertAllowed(
            'https://evil.example/webhook',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
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
