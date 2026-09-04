<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

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
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\Mcp\Api\UcpMcpProxyController;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\DefaultHttpRequestContextFactory;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class UcpMcpProxyControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController')) {
            eval('namespace Shopware\\Core\\Framework\\Mcp\\Controller { final class StoreApiMcpServerController {} }');
        }
    }

    #[Test]
    public function testItReturnsStructuredErrorWhenHostIsUnknown(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $controller = $this->controller(
            new SalesChannelDomainCollection(),
            new SalesChannelCollection(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
        );

        $response = $controller->proxy(Request::create('https://unknown.example/ucp/mcp'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
        self::assertSame('UCP MCP is not available for this host.', $payload['messages'][0]['content']);
    }

    #[Test]
    public function testItRejectsWhenUcpIsInactive(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: false, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testItRejectsWhenMcpTransportIsDisabled(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['rest']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testItRejectsWhenStoreApiMcpIsUnavailable(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
            new ShopwareVersionDetector(versionOverride: '6.6.0.0'),
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testItMapsSignatureFailuresWithoutDispatching(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::once())
            ->method('create')
            ->willThrowException(new SignatureException('Missing request signature for strict policy request.'));

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString('Missing request signature', (string) $response->getContent());
    }

    #[Test]
    public function testItMapsValidationFailuresWithoutDispatching(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::once())
            ->method('create')
            ->willThrowException(new ValidationException('Invalid UCP request.', ['$.headers.ucp-agent is invalid']));

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_POST));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('$.headers.ucp-agent is invalid', $payload['messages'][0]['content']);
    }

    #[Test]
    public function testItRejectsDisallowedAgentProfileHostThroughSdkFactory(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $agentProfileFetcher = $this->createMock(AgentProfileFetcherInterface::class);
        $agentProfileFetcher->expects(self::never())->method('fetch');

        $requestSignatureService = $this->createMock(RequestSignatureServiceInterface::class);
        $requestSignatureService->expects(self::never())->method('verify');

        $capabilityNegotiator = $this->createMock(CapabilityNegotiatorInterface::class);
        $capabilityNegotiator->expects(self::never())->method('negotiate');

        $requestContextFactory = new DefaultHttpRequestContextFactory(
            new class implements RuntimeConfigurationResolverInterface {
                public function resolve(HttpRequest $request): RuntimeConfiguration
                {
                    return new RuntimeConfiguration(
                        '2026-08-25',
                        'https://shop.example',
                        SignaturePolicy::Log,
                        true,
                        ['trusted.example'],
                    );
                }
            },
            $agentProfileFetcher,
            $requestSignatureService,
            $capabilityNegotiator,
        );

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $request = Request::create(
            'https://shop.example/ucp/mcp',
            Request::METHOD_POST,
            server: ['HTTP_UCP_AGENT' => 'profile="https://evil.example/profile"'],
        );

        $response = $controller->proxy($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString('Platform profile host is not allowed', (string) $response->getContent());
    }

    #[Test]
    public function testItRejectsOversizedBodiesBeforeSignatureVerification(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
            sdkConfiguration: $this->sdkConfiguration(maxRequestBodyBytes: 4),
        );

        $response = $controller->proxy(Request::create(
            'https://shop.example/ucp/mcp',
            Request::METHOD_POST,
            content: '12345',
        ));

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    }

    #[Test]
    public function testItProxiesWithoutForwardingBrowserCookies(): void
    {
        $accessKey = 'store-api-access-key';
        $context = new RequestContext('shop.example');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (HttpRequest $request): bool {
                self::assertSame(Request::METHOD_POST, $request->method);
                self::assertSame('https://shop.example/ucp/mcp?foo=bar', $request->absoluteUri);
                self::assertSame('bar', $request->query['foo']);
                self::assertSame('keep-me', $request->headers['idempotency-key']);
                self::assertSame('{"jsonrpc":"2.0","method":"tools/list","id":1}', $request->body);

                return true;
            }))
            ->willReturn($context);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::once())
            ->method('handle')
            ->with(
                self::callback(static function (Request $request) use ($accessKey, $context): bool {
                    self::assertSame('/store-api/_mcp', $request->getPathInfo());
                    self::assertSame('foo=bar', $request->getQueryString());
                    self::assertSame([], $request->cookies->all());
                    self::assertNull($request->headers->get('cookie'));
                    self::assertSame($accessKey, $request->headers->get(PlatformRequest::HEADER_ACCESS_KEY));
                    self::assertSame('keep-me', $request->headers->get('idempotency-key'));
                    self::assertSame($context, $request->attributes->get(SymfonyRequestContextFactory::REQUEST_CONTEXT_ATTRIBUTE));
                    self::assertNull($request->headers->get('sw-secret-access-key'));

                    return true;
                }),
                HttpKernelInterface::SUB_REQUEST,
            )
            ->willReturn(new Response('proxied'));

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels($accessKey),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $request = Request::create(
            'https://shop.example/ucp/mcp?foo=bar',
            Request::METHOD_POST,
            server: [
                'HTTP_COOKIE' => 'sw-cache-hash=abc; session=secret',
                'HTTP_SW_SECRET_ACCESS_KEY' => 'client-provided-secret',
                'HTTP_IDEMPOTENCY_KEY' => 'keep-me',
            ],
            content: '{"jsonrpc":"2.0","method":"tools/list","id":1}',
        );

        $response = $controller->proxy($request);

        self::assertSame('proxied', $response->getContent());
        self::assertSame($context, $request->attributes->get(SymfonyRequestContextFactory::REQUEST_CONTEXT_ATTRIBUTE));
    }

    #[Test]
    public function testItDoesNotBypassGatingForOptionsRequests(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::never())->method('handle');

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: false, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_OPTIONS));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function testItForwardsOptionsRequestsWithoutSignatureVerificationWhenMcpIsAvailable(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel->expects(self::once())
            ->method('handle')
            ->with(self::isInstanceOf(Request::class), HttpKernelInterface::SUB_REQUEST)
            ->willReturn(new Response('', Response::HTTP_NO_CONTENT));

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $controller = $this->controller(
            $this->knownDomains(),
            $this->knownSalesChannels(),
            new UcpConfig(active: true, enabledTransports: ['mcp']),
            $kernel,
            $requestContextFactory,
        );

        $response = $controller->proxy(Request::create('https://shop.example/ucp/mcp', Request::METHOD_OPTIONS));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    private function controller(
        SalesChannelDomainCollection $domains,
        SalesChannelCollection $salesChannels,
        UcpConfig $config,
        ?HttpKernelInterface $kernel = null,
        ?HttpRequestContextFactoryInterface $requestContextFactory = null,
        ?ShopwareVersionDetector $versionDetector = null,
        ?UcpSdkConfiguration $sdkConfiguration = null,
    ): UcpMcpProxyController {
        return new UcpMcpProxyController(
            $kernel ?? $this->createMock(HttpKernelInterface::class),
            $this->domainResolver($domains),
            $this->salesChannelRepository($salesChannels),
            $this->configService($config),
            $versionDetector ?? new ShopwareVersionDetector(versionOverride: '6.7.0.0'),
            new SymfonyRequestContextFactory($requestContextFactory ?? $this->requestContextFactory()),
            $sdkConfiguration ?? $this->sdkConfiguration(),
        );
    }

    private function requestContextFactory(): HttpRequestContextFactoryInterface
    {
        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->method('create')->willReturn(new RequestContext('shop.example'));

        return $requestContextFactory;
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = new class($config) implements UcpConfigRepositoryInterface {
            public function __construct(private UcpConfig $config)
            {
            }

            public function find(string $salesChannelId): UcpConfig
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

        return new UcpConfigService($repository, $legacyStore);
    }

    private function sdkConfiguration(int $maxRequestBodyBytes = 1048576): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            '2026-08-25',
            null,
            [],
            'strict',
            [],
            true,
            86400,
            $maxRequestBodyBytes,
            300,
            86400,
            300,
            300,
            [],
            true,
            'default',
            'ES256',
            '+30 days',
            '+30 days',
            1048576,
            5,
            false,
            'sqlite:///:memory:',
        );
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

    /**
     * @return EntityRepository<SalesChannelCollection>
     */
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

    private function knownDomains(): SalesChannelDomainCollection
    {
        return new SalesChannelDomainCollection([
            $this->salesChannelDomain('22222222222222222222222222222222'),
        ]);
    }

    private function knownSalesChannels(string $accessKey = 'store-api-access-key'): SalesChannelCollection
    {
        return new SalesChannelCollection([
            $this->salesChannel('22222222222222222222222222222222', $accessKey),
        ]);
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
