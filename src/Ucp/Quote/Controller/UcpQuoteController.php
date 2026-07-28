<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Quote\Controller;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Capability\QuoteCapability;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerCredential;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

/**
 * Transport for the vendor capability `com.shopware.quote`.
 *
 * Everything reusable is reused from the SDK HTTP layer: the request context
 * (and with it the UCP-Agent requirement and signature policy) comes from
 * SymfonyRequestContextFactory, responses from UcpResponseFactory, and errors
 * from the SDK's ExceptionListener - both listeners already match `/ucp/*`.
 *
 * Only the routing is plugin-owned, and only because it has to be: the SDK's
 * ShoppingOperationExecutor dispatches a fixed set of `dev.ucp.*` operations and
 * its negotiator derives operations from the seven known SDK capability
 * interfaces, so a vendor capability contributes no operations and cannot be
 * routed there. Fold these routes into the shared operation layer as soon as the
 * SDK gains an extensible operation registry.
 *
 * @internal
 */
#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
    'auth_required' => false,
])]
#[Package('framework')]
final class UcpQuoteController
{
    private const DEFAULT_LIST_LIMIT = 25;

    public function __construct(
        private readonly QuoteCapability $quoteCapability,
        private readonly SymfonyRequestContextFactory $requestContextFactory,
        private readonly UcpResponseFactory $responseFactory,
        private readonly string $quoteSchemaPath,
    ) {
    }

    #[Route(path: '/ucp/quotes', name: 'frontend.ucp.quote.request', methods: ['POST'])]
    public function requestQuote(Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $payload = $this->payload($request);

        $snapshot = $this->quoteCapability->requestQuote(
            $this->credential($request),
            $this->lineItems($payload),
            $this->comment($payload),
            $context,
        );

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_CREATED, [], $context, 'quote.request');
    }

    #[Route(path: '/ucp/quotes', name: 'frontend.ucp.quote.list', methods: ['GET'])]
    public function listQuotes(Request $request): JsonResponse
    {
        $context = $this->requestContext($request);

        $list = $this->quoteCapability->listQuotes(
            $this->credential($request),
            $request->query->getInt('limit', self::DEFAULT_LIST_LIMIT),
            $request->query->getInt('page', 1),
            $context,
        );

        return $this->responseFactory->success($list->toArray(), Response::HTTP_OK, [], $context, 'quote.list');
    }

    #[Route(path: '/ucp/quotes/{id}', name: 'frontend.ucp.quote.get', methods: ['GET'])]
    public function getQuote(string $id, Request $request): JsonResponse
    {
        $context = $this->requestContext($request);

        $snapshot = $this->quoteCapability->getQuote($this->credential($request), $id, $context);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.get');
    }

    #[Route(path: '/ucp/quotes/{id}/counter', name: 'frontend.ucp.quote.counter', methods: ['POST'])]
    public function counterQuote(string $id, Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $payload = $this->payload($request);

        $snapshot = $this->quoteCapability->counterQuote(
            $this->credential($request),
            $id,
            $this->lineItems($payload),
            $this->comment($payload),
            $context,
        );

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.counter');
    }

    #[Route(path: '/ucp/quotes/{id}/accept', name: 'frontend.ucp.quote.accept', methods: ['POST'])]
    public function acceptQuote(string $id, Request $request): JsonResponse
    {
        $context = $this->requestContext($request);

        $snapshot = $this->quoteCapability->acceptQuote($this->credential($request), $id, $context);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.accept');
    }

    #[Route(path: '/ucp/quotes/{id}/decline', name: 'frontend.ucp.quote.decline', methods: ['POST'])]
    public function declineQuote(string $id, Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $payload = $this->payload($request);

        $snapshot = $this->quoteCapability->declineQuote($this->credential($request), $id, $this->comment($payload), $context);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.decline');
    }

    /**
     * The capability contract is served by the plugin so discovery resolves
     * without any central infrastructure.
     */
    #[Route(path: UcpCapabilityCatalog::QUOTE_SCHEMA_PATH, name: 'frontend.ucp.quote.schema', methods: ['GET'])]
    public function schema(): Response
    {
        $schema = @file_get_contents($this->quoteSchemaPath);

        if (!\is_string($schema)) {
            return new Response('{}', Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/json']);
        }

        return new Response($schema, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function requestContext(Request $request): RequestContext
    {
        return $this->requestContextFactory->get($request) ?? $this->requestContextFactory->create($request);
    }

    /**
     * Prefers the identity-linking access token: it names the customer, carries
     * scopes, and can be revoked. A customer context token stays accepted for
     * agents already holding one (embedded checkout), but it is unscoped.
     */
    private function credential(Request $request): AgentCustomerCredential
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            return AgentCustomerCredential::fromAccessToken(trim(substr($authorization, 7)));
        }

        $contextToken = $request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN)
            ?? $request->headers->get('sw-context-token');

        if (!\is_string($contextToken) || '' === $contextToken) {
            throw new ValidationException('Quote operations require an identity-linking access token or a customer context token.', ['$.headers.authorization or $.headers.sw-context-token is required']);
        }

        return AgentCustomerCredential::fromContextToken($contextToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();

        if ('' === $content) {
            return [];
        }

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Request body must be valid JSON.', ['$ must be a JSON object']);
        }

        if (!\is_array($payload)) {
            throw new ValidationException('Request body must be a JSON object.', ['$ must be a JSON object']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function lineItems(array $payload): array
    {
        $lineItems = $payload['line_items'] ?? [];

        if (!\is_array($lineItems)) {
            throw new ValidationException('Line items must be an array.', ['$.line_items must be an array']);
        }

        return array_values(array_filter($lineItems, 'is_array'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function comment(array $payload): ?string
    {
        $comment = $payload['comment'] ?? null;

        return \is_string($comment) ? $comment : null;
    }
}
