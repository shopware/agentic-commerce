<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessException;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessService;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Runs the same service as the `swag-agentic-commerce:readiness:*` commands. Guarded by the core
 * privileges for the rows it reads and creates, not by a UCP role: readiness must not need UCP rights.
 * `sales_channel:read` is required too, because the response lists sales channel ids and names.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('discovery')]
final class BasicAiReadinessController
{
    private const INVALID_SALES_CHANNEL_IDS = 'salesChannelIds must be a non-empty list of sales channel ids; omit it to select every Storefront and Headless sales channel.';

    public function __construct(private readonly BasicAiReadinessService $readinessService)
    {
    }

    #[Route(path: '/api/_admin/agentic-commerce/readiness/status', name: 'api.action.swag_agentic_commerce.readiness.status', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['sales_channel:read', 'sales_channel_file:read']])]
    public function status(Request $request, Context $context): JsonResponse
    {
        if (!$request->query->has('salesChannelIds')) {
            return new JsonResponse(['data' => $this->readinessService->status(null, $context)]);
        }

        try {
            $salesChannelIds = $request->query->all('salesChannelIds');
        } catch (BadRequestException) {
            throw BasicAiReadinessException::invalidPayload(self::INVALID_SALES_CHANNEL_IDS);
        }

        return new JsonResponse(['data' => $this->readinessService->status($this->salesChannelIds($salesChannelIds), $context)]);
    }

    #[Route(path: '/api/_admin/agentic-commerce/readiness/enable', name: 'api.action.swag_agentic_commerce.readiness.enable', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['sales_channel:read', 'sales_channel_file:read', 'sales_channel_file:create']])]
    public function enable(Request $request, Context $context): JsonResponse
    {
        try {
            $payload = '' === $request->getContent() ? [] : $request->toArray();
        } catch (JsonException) {
            throw BasicAiReadinessException::invalidPayload('the body must be a JSON object.');
        }

        $dryRun = $payload['dryRun'] ?? false;
        if (!\is_bool($dryRun)) {
            throw BasicAiReadinessException::invalidPayload('dryRun must be a boolean.');
        }

        $salesChannelIds = $payload['salesChannelIds'] ?? null;

        return new JsonResponse(['data' => $this->readinessService->enable(
            null === $salesChannelIds ? null : $this->salesChannelIds($salesChannelIds),
            $dryRun,
            $context,
        )]);
    }

    /**
     * @return non-empty-list<string>
     */
    private function salesChannelIds(mixed $salesChannelIds): array
    {
        if (
            !\is_array($salesChannelIds)
            || [] === $salesChannelIds
            || !array_is_list($salesChannelIds)
            || [] !== array_filter($salesChannelIds, static fn (mixed $salesChannelId): bool => !\is_string($salesChannelId))
        ) {
            throw BasicAiReadinessException::invalidPayload(self::INVALID_SALES_CHANNEL_IDS);
        }

        /* @var non-empty-list<string> $salesChannelIds */
        return $salesChannelIds;
    }
}
