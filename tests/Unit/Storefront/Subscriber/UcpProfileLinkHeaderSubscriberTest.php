<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\Storefront\Subscriber\UcpProfileLinkHeaderSubscriber;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Package('discovery')]
#[CoversClass(UcpProfileLinkHeaderSubscriber::class)]
final class UcpProfileLinkHeaderSubscriberTest extends TestCase
{
    #[Test]
    public function itAppendsTheUcpProfileLinkForAnActiveHtmlSalesChannel(): void
    {
        $response = new Response('', Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Link' => '<https://shop.example/products>; rel="canonical"',
        ]);

        $this->subscriber(new UcpConfig(active: true), $this->domain())->onBeforeSendResponse($this->responseEvent($response));

        static::assertSame([
            '<https://shop.example/products>; rel="canonical"',
            '</.well-known/ucp>; rel="service-meta"',
        ], $response->headers->all('Link'));
    }

    #[Test]
    public function itDoesNotAddTheLinkWhenUcpIsInactive(): void
    {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'text/html']);

        $this->subscriber(new UcpConfig(active: false), $this->domain())->onBeforeSendResponse($this->responseEvent($response));

        static::assertSame([], $response->headers->all('Link'));
    }

    #[Test]
    public function itDoesNotAddTheLinkForAnUnknownSalesChannelDomain(): void
    {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'text/html']);

        $this->subscriber(new UcpConfig(active: true))
            ->onBeforeSendResponse($this->responseEvent($response));

        static::assertSame([], $response->headers->all('Link'));
    }

    private function subscriber(
        UcpConfig $config,
        ?SalesChannelDomainEntity $domain = null,
    ): UcpProfileLinkHeaderSubscriber {
        $configRepository = $this->createStub(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn($config);

        $configService = new UcpConfigService(
            $configRepository,
            $this->createStub(LegacyConfigStoreInterface::class),
        );
        /** @var StaticEntityRepository<SalesChannelDomainCollection> $domainRepository */
        $domainRepository = new StaticEntityRepository([
            new SalesChannelDomainCollection(null === $domain ? [] : [$domain]),
        ]);
        $domainResolver = new SalesChannelDomainResolver($domainRepository);

        return new UcpProfileLinkHeaderSubscriber($configService, $domainResolver);
    }

    private function responseEvent(Response $response): BeforeSendResponseEvent
    {
        return new BeforeSendResponseEvent(Request::create('https://shop.example/products'), $response);
    }

    private function domain(): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');
        $domain->setUrl('https://shop.example');

        return $domain;
    }
}
