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
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Package('checkout')]
final readonly class UcpMcpProxyController
{
    /**
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private HttpKernelInterface $httpKernel,
        private SalesChannelDomainResolver $domainResolver,
        private EntityRepository $salesChannelRepository,
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
        $accessKey = $this->resolveSalesChannelAccessKey($request);
        if (null === $accessKey) {
            return $this->errorResponse('UCP MCP is not available for this host.', Response::HTTP_FORBIDDEN);
        }

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
        // Store API MCP is trunk-only and requires a sales-channel access key.
        // Keep the key server-side: clients discover /ucp/mcp, while this
        // internal sub-request authenticates against /store-api/_mcp.
        $subRequest->headers->set(PlatformRequest::HEADER_ACCESS_KEY, $accessKey);
        $subRequest->headers->remove('sw-secret-access-key');
        $subRequest->headers->remove('cookie');

        return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    private function resolveSalesChannelAccessKey(Request $request): ?string
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

        return $salesChannel->getAccessKey();
    }

    private function errorResponse(string $message, int $statusCode): Response
    {
        return new Response(
            json_encode([
                'ucp' => [
                    'status' => 'error',
                ],
                'messages' => [[
                    'type' => 'error',
                    'content' => $message,
                ]],
            ], \JSON_THROW_ON_ERROR),
            $statusCode,
            ['Content-Type' => 'application/json'],
        );
    }
}
