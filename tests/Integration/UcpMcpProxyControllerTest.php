<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\Mcp\Api\UcpMcpProxyController;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** @internal */
final class UcpMcpProxyControllerTest extends TestCase
{
    #[Test]
    public function testItReturnsStructuredErrorWhenHostIsUnknown(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $controller = new UcpMcpProxyController(
            $kernel,
            $this->domainResolver(new SalesChannelDomainCollection()),
            $this->salesChannelRepository(new SalesChannelCollection()),
        );

        $response = $controller->proxy(Request::create('https://unknown.example/ucp/mcp'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
        self::assertSame('UCP MCP is not available for this host.', $payload['messages'][0]['content']);
    }

    #[Test]
    public function testItProxiesWithoutForwardingBrowserCookies(): void
    {
        $salesChannelId = '22222222222222222222222222222222';
        $accessKey = 'store-api-access-key';

        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::once())
            ->method('handle')
            ->with(
                self::callback(static function (Request $request) use ($accessKey): bool {
                    self::assertSame('/store-api/_mcp', $request->getPathInfo());
                    self::assertSame('foo=bar', $request->getQueryString());
                    self::assertSame([], $request->cookies->all());
                    self::assertNull($request->headers->get('cookie'));
                    self::assertSame($accessKey, $request->headers->get(PlatformRequest::HEADER_ACCESS_KEY));
                    self::assertNull($request->headers->get('sw-secret-access-key'));

                    return true;
                }),
                HttpKernelInterface::SUB_REQUEST,
            )
            ->willReturn(new Response('proxied'));

        $controller = new UcpMcpProxyController(
            $kernel,
            $this->domainResolver(new SalesChannelDomainCollection([
                $this->salesChannelDomain($salesChannelId),
            ])),
            $this->salesChannelRepository(new SalesChannelCollection([
                $this->salesChannel($salesChannelId, $accessKey),
            ])),
        );

        $request = Request::create(
            'https://shop.example/ucp/mcp?foo=bar',
            Request::METHOD_POST,
            server: [
                'HTTP_COOKIE' => 'sw-cache-hash=abc; session=secret',
                'HTTP_SW_SECRET_ACCESS_KEY' => 'client-provided-secret',
            ],
            content: '{"jsonrpc":"2.0","method":"tools/list","id":1}',
        );

        $response = $controller->proxy($request);

        self::assertSame('proxied', $response->getContent());
    }

    private function domainResolver(SalesChannelDomainCollection $domains): SalesChannelDomainResolver
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                $domains->count(),
                $domains,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelDomainResolver($repository);
    }

    private function salesChannelRepository(SalesChannelCollection $salesChannels): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel',
                $salesChannels->count(),
                $salesChannels,
                null,
                $criteria,
                $context,
            ),
        );

        return $repository;
    }

    private function salesChannelDomain(string $salesChannelId): SalesChannelDomainEntity
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('11111111111111111111111111111111');
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId('33333333333333333333333333333333');
        $domain->setCurrencyId('44444444444444444444444444444444');
        $domain->setUrl('https://shop.example');

        return $domain;
    }

    private function salesChannel(string $salesChannelId, string $accessKey): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);
        $salesChannel->setAccessKey($accessKey);

        return $salesChannel;
    }
}
