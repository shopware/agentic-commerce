<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration;

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
    #[Test]
    public function testItBlocksEmbeddedRequestsWhenAllowedOriginsAreEmpty(): void
    {
        $listener = new EmbeddedResponseListener(
            $this->configService(new UcpConfig(active: true, embeddedAllowedOrigins: [])),
            $this->emptyDomainResolver(),
        );

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://shop.example/ucp/embedded/cart/cart-id'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function testItAllowsConfiguredEmbeddedOrigin(): void
    {
        $listener = new EmbeddedResponseListener(
            $this->configService(new UcpConfig(active: true, embeddedAllowedOrigins: ['https://assistant.example'])),
            $this->emptyDomainResolver(),
        );

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('https://shop.example/ucp/embedded/cart/cart-id', server: ['HTTP_ORIGIN' => 'https://assistant.example']),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    #[Test]
    public function testItHandlesEmbeddedPreflightRequestsBeforeShopwareCorsFallbacks(): void
    {
        $listener = new EmbeddedResponseListener(
            $this->configService(new UcpConfig(
                active: true,
                embeddedAllowedOrigins: ['https://assistant.example'],
                embeddedFrameAncestors: ['https://assistant.example'],
            )),
            $this->emptyDomainResolver(),
        );

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create(
                'https://shop.example/ucp/embedded/cart/cart-id',
                Request::METHOD_OPTIONS,
                server: ['HTTP_ORIGIN' => 'https://assistant.example'],
            ),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $listener->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_NO_CONTENT, $event->getResponse()->getStatusCode());
        self::assertSame('https://assistant.example', $event->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('GET, OPTIONS', $event->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Accept', $event->getResponse()->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('frame-ancestors https://assistant.example', $event->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = new class($config) implements UcpConfigRepositoryInterface {
            public function __construct(private UcpConfig $config)
            {
            }

            public function find(string $salesChannelId): ?UcpConfig
            {
                return $this->config;
            }

            public function findMany(array $salesChannelIds): array
            {
                return [];
            }

            public function save(string $salesChannelId, UcpConfig $config): void
            {
            }
        };

        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(
            static function (string $key) use ($config): mixed {
                return match ($key) {
                    'SwagAgenticCommerce.config.active' => $config->active,
                    'SwagAgenticCommerce.config.embeddedAllowedOrigins' => $config->embeddedAllowedOrigins,
                    'SwagAgenticCommerce.config.embeddedFrameAncestors' => $config->embeddedFrameAncestors,
                    default => null,
                };
            },
        );

        return new UcpConfigService($repository, $legacyStore);
    }

    private function emptyDomainResolver(): SalesChannelDomainResolver
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                0,
                new EntityCollection(),
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelDomainResolver($repository);
    }
}
