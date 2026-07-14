<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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

    #[Test]
    public function testItFallsBackToAnySalesChannelDomainHostWithoutAllowlists(): void
    {
        $guard = new CheckoutWebhookUrlGuard($this->viewProviderWithDomains([
            'https://shop-a.example',
            'https://shop-b.example',
        ]));

        $guard->assertAllowed(
            'https://shop-b.example/_action/webhooks',
            new UcpConfig(),
            'sales-channel-id',
        );

        self::addToAssertionCount(1);
    }

    /**
     * @param list<string> $domainUrls
     */
    private function viewProviderWithDomains(array $domainUrls): SalesChannelViewProvider
    {
        $domains = new SalesChannelDomainCollection();
        foreach ($domainUrls as $index => $url) {
            $domain = new SalesChannelDomainEntity();
            $domain->setId(str_pad((string) ($index + 1), 32, '0', \STR_PAD_LEFT));
            $domain->setUrl($url);
            $domains->add($domain);
        }

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(str_pad('f', 32, 'f'));
        $salesChannel->setDomains($domains);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel',
                1,
                new EntityCollection([$salesChannel]),
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelViewProvider($repository);
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
