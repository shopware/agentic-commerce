<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class CheckoutWebhookUrlGuardTest extends TestCase
{
    private CheckoutWebhookUrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CheckoutWebhookUrlGuard(new SalesChannelViewProvider(
            $this->createMock(EntityRepository::class),
        ));
    }

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
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override URLs must use https and include a host.');

        $this->guard->assertAllowed(
            'file:///etc/passwd',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
        );
    }

    #[Test]
    public function testItRejectsHttpWebhookUrls(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override URLs must use https and include a host.');

        $this->guard->assertAllowed(
            'http://agent.example/webhook',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
        );
    }

    #[Test]
    public function testItAllowsLocalHttpWebhookWhenExplicitlyEnabled(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->uninitialized(SalesChannelViewProvider::class), true);

        $guard->assertAllowed(
            'http://sw66.localhost:8088/ucp/webhook',
            new UcpConfig(agentAllowlist: ['sw66.localhost']),
            'sales-channel-id',
        );

        self::addToAssertionCount(1);
    }

    #[Test]
    public function testItRejectsNonLocalHttpWebhookEvenWhenLocalHttpIsAllowed(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->uninitialized(SalesChannelViewProvider::class), true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override URLs must use https and include a host.');

        $guard->assertAllowed(
            'http://agent.example/webhook',
            new UcpConfig(agentAllowlist: ['agent.example']),
            'sales-channel-id',
        );
    }

    #[Test]
    public function testItRejectsWebhookHostsOutsideAllowlist(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Webhook override host "evil.example" is not permitted.');

        $this->guard->assertAllowed(
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
