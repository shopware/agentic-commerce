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
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Swag\AgenticCommerce\Storefront\Subscriber\UcpProfileLinkHeaderSubscriber;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
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

    #[Test]
    public function itAddsNoLinkForAPathPrefixedDomain(): void
    {
        $htmlResponse = new Response('', Response::HTTP_OK, ['Content-Type' => 'text/html']);
        $requestWithStrippedPrefix = Request::create('https://shop.example/products');
        $requestWithStrippedPrefix->attributes->set(RequestTransformer::ORIGINAL_REQUEST_URI, '/de/products');

        $this->subscriber(new UcpConfig(active: true), $this->domain('https://shop.example/de'))
            ->onBeforeSendResponse(new BeforeSendResponseEvent($requestWithStrippedPrefix, $htmlResponse));

        static::assertSame([], $htmlResponse->headers->all('Link'));
    }

    #[Test]
    public function itAppendsTheLinkWhenBuiltWithTheArgumentsOfThe140Container(): void
    {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'text/html']);

        (new UcpProfileLinkHeaderSubscriber($this->configService(new UcpConfig(active: true)), $this->domainResolver($this->domain())))
            ->onBeforeSendResponse($this->responseEvent($response));

        static::assertSame(['</.well-known/ucp>; rel="service-meta"'], $response->headers->all('Link'));
    }

    private function subscriber(
        UcpConfig $config,
        ?SalesChannelDomainEntity $domain = null,
    ): UcpProfileLinkHeaderSubscriber {
        return new UcpProfileLinkHeaderSubscriber($this->configService($config), $this->domainResolver($domain), new UcpProfileLocator());
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $configRepository = $this->createStub(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn($config);

        return new UcpConfigService(
            $configRepository,
            $this->createStub(LegacyConfigStoreInterface::class),
        );
    }

    private function domainResolver(?SalesChannelDomainEntity $domain): SalesChannelDomainResolver
    {
        /** @var StaticEntityRepository<SalesChannelDomainCollection> $domainRepository */
        $domainRepository = new StaticEntityRepository([
            new SalesChannelDomainCollection(null === $domain ? [] : [$domain]),
        ]);

        return new SalesChannelDomainResolver($domainRepository);
    }

    private function responseEvent(Response $response): BeforeSendResponseEvent
    {
        return new BeforeSendResponseEvent(Request::create('https://shop.example/products'), $response);
    }

    private function domain(string $url = 'https://shop.example'): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');
        $domain->setUrl($url);

        return $domain;
    }
}
