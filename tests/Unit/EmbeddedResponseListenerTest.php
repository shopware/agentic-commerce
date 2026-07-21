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
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Embedded\EmbeddedResponseListener;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** @internal */
final class EmbeddedResponseListenerTest extends TestCase
{
    private UcpConfig $config;

    private EmbeddedResponseListener $listener;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->config = new UcpConfig(active: true, embeddedAllowedOrigins: []);
        $this->kernel = $this->createMock(HttpKernelInterface::class);

        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(fn (): UcpConfig => $this->config);

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(fn (string $key): mixed => match ($key) {
            'SwagAgenticCommerce.config.active' => $this->config->active,
            'SwagAgenticCommerce.config.embeddedAllowedOrigins' => $this->config->embeddedAllowedOrigins,
            'SwagAgenticCommerce.config.embeddedFrameAncestors' => $this->config->embeddedFrameAncestors,
            default => null,
        });

        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                0,
                new EntityCollection(),
                null,
                $criteria,
                $context,
            ),
        );

        $this->listener = new EmbeddedResponseListener(
            new UcpConfigService($repository, $legacyStore),
            new SalesChannelDomainResolver($domainRepository),
        );
    }

    #[Test]
    public function testItBlocksEmbeddedRequestsWhenAllowedOriginsAreEmpty(): void
    {
        $event = new RequestEvent(
            $this->kernel,
            Request::create('https://shop.example/ucp/embedded/cart/cart-id'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function testItAllowsConfiguredEmbeddedOrigin(): void
    {
        $this->config = new UcpConfig(active: true, embeddedAllowedOrigins: ['https://assistant.example']);

        $event = new RequestEvent(
            $this->kernel,
            Request::create('https://shop.example/ucp/embedded/cart/cart-id', server: ['HTTP_ORIGIN' => 'https://assistant.example']),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    #[Test]
    public function testItAllowsSameOriginRequestsWithNoOriginHeader(): void
    {
        $this->config = new UcpConfig(active: true, embeddedAllowedOrigins: ['https://assistant.example']);

        $event = new RequestEvent(
            $this->kernel,
            Request::create('https://shop.example/ucp/embedded/cart/cart-id'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    #[Test]
    public function testItHandlesEmbeddedPreflightRequestsBeforeShopwareCorsFallbacks(): void
    {
        $this->config = new UcpConfig(
            active: true,
            embeddedAllowedOrigins: ['https://assistant.example'],
            embeddedFrameAncestors: ['https://assistant.example'],
        );

        $event = new RequestEvent(
            $this->kernel,
            Request::create(
                'https://shop.example/ucp/embedded/cart/cart-id',
                Request::METHOD_OPTIONS,
                server: ['HTTP_ORIGIN' => 'https://assistant.example'],
            ),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NO_CONTENT, $event->getResponse()->getStatusCode());
        self::assertSame('https://assistant.example', $event->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('GET, OPTIONS', $event->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Accept', $event->getResponse()->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('frame-ancestors https://assistant.example', $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function testItAnswersOriginLessPreflightsWithoutACorsGrant(): void
    {
        $this->config = new UcpConfig(
            active: true,
            embeddedAllowedOrigins: ['https://assistant.example'],
        );

        $event = new RequestEvent(
            $this->kernel,
            Request::create('https://shop.example/ucp/embedded/cart/cart-id', Request::METHOD_OPTIONS),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NO_CONTENT, $event->getResponse()->getStatusCode());
        self::assertFalse($event->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }
}
