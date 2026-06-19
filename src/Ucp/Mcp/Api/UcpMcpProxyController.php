<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

#[Package('checkout')]
final class UcpMcpProxyController
{
    /**
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly EntityRepository $salesChannelRepository,
        private readonly UcpConfigService $configService,
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly SymfonyRequestContextFactory $requestContextFactory,
        private readonly UcpSdkConfiguration $sdkConfiguration,
    ) {
    }

    #[Route(
        path: '/ucp/mcp',
        name: 'swag_agentic_commerce.ucp.mcp.proxy',
        defaults: [
            PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID, StoreApiRouteScope::ID],
            'auth_required' => false,
        ],
        methods: [Request::METHOD_GET, Request::METHOD_POST, Request::METHOD_DELETE, Request::METHOD_OPTIONS],
    )]
    public function proxy(Request $request): Response
    {
        $salesChannel = $this->resolveSalesChannel($request);
        if (null === $salesChannel) {
            return $this->errorResponse('UCP MCP is not available for this host.', Response::HTTP_FORBIDDEN);
        }

        $config = $this->configService->getConfig($salesChannel['salesChannelId']);
        if (
            !$config->active
            || !\in_array(Transport::Mcp, $config->runtimeTransports($this->versionDetector->supportsStoreApiMcp()), true)
        ) {
            return $this->errorResponse('UCP MCP is not available for this host.', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            return $this->dispatchToStoreApiMcp($request, $salesChannel['accessKey']);
        }

        $body = $request->getContent();
        if (\strlen($body) > $this->sdkConfiguration->maxRequestBodyBytes) {
            return $this->errorResponse(
                'Request body exceeds the maximum allowed size.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                [['type' => 'error', 'content' => '$.body exceeds the maximum allowed size']],
            );
        }

        try {
            $context = $this->requestContextFactory->create($request, $body);
        } catch (SignatureException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_UNAUTHORIZED);
        } catch (ValidationException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                array_map(
                    static fn (string $violation): array => ['type' => 'error', 'content' => $violation],
                    $exception->getViolations(),
                ),
            );
        } catch (UcpException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $this->requestContextFactory->set($request, $context);

        return $this->dispatchToStoreApiMcp($request, $salesChannel['accessKey'], $context);
    }

    private function dispatchToStoreApiMcp(Request $request, string $accessKey, ?RequestContext $context = null): Response
    {
        $subRequest = Request::create(
            '/store-api/_mcp'.('' !== $request->getQueryString() ? '?'.$request->getQueryString() : ''),
            $request->getMethod(),
            [],
            [],
            [],
            $request->server->all(),
            $request->getContent(),
        );
        $subRequest->headers->replace($request->headers->all());
        if (null !== $context) {
            $this->requestContextFactory->set($subRequest, $context);
        }

        // Store API MCP is trunk-only and requires a sales-channel access key.
        // Keep the key server-side: clients discover /ucp/mcp, while this
        // internal sub-request authenticates against /store-api/_mcp.
        $subRequest->headers->set(PlatformRequest::HEADER_ACCESS_KEY, $accessKey);
        $subRequest->headers->remove('sw-secret-access-key');
        $subRequest->headers->remove('cookie');

        return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * @return array{salesChannelId: string, accessKey: string}|null
     */
    private function resolveSalesChannel(Request $request): ?array
    {
        $resolution = $this->domainResolver->resolveByAbsoluteUri($request->getUri());
        if (null === $resolution) {
            return null;
        }

        $salesChannel = $this->salesChannelRepository
            ->search(new Criteria([$resolution->salesChannelId]), Context::createDefaultContext())
            ->first();

        if (!$salesChannel instanceof SalesChannelEntity) {
            return null;
        }

        $accessKey = $salesChannel->getAccessKey();
        if ('' === $accessKey) {
            return null;
        }

        return [
            'salesChannelId' => $resolution->salesChannelId,
            'accessKey' => $accessKey,
        ];
    }

    /**
     * @param list<array<string, string>>|null $messages
     */
    private function errorResponse(string $message, int $statusCode, ?array $messages = null): Response
    {
        return new Response(
            json_encode([
                'ucp' => [
                    'status' => 'error',
                ],
                'messages' => null !== $messages && [] !== $messages ? $messages : [[
                    'type' => 'error',
                    'content' => $message,
                ]],
            ], \JSON_THROW_ON_ERROR),
            $statusCode,
            ['Content-Type' => 'application/json'],
        );
    }
}
