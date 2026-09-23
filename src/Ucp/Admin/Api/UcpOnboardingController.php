<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Onboarding\BulkUcpActivator;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelActivationOutcome;
use Swag\AgenticCommerce\Ucp\Onboarding\ShopReadinessProvider;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Enum\Transport;

/**
 * Backs the Agentic Commerce onboarding surface.
 *
 * `readiness` is the settings page in one read: status, the three setup steps and
 * every offerable channel with its outstanding findings. `bulk-enable` is what the
 * prepare flow posts; it responds 200 with per-channel outcomes even when some
 * channels failed, because partial success is the normal case for a bulk operation.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('framework')]
final class UcpOnboardingController
{
    public function __construct(
        private readonly BulkUcpActivator $activator,
        private readonly ShopReadinessProvider $readinessProvider,
    ) {
    }

    #[Route(
        path: '/api/_admin/ucp/onboarding/readiness',
        name: 'api.action.swag_agentic_commerce.ucp.onboarding.readiness',
        methods: ['GET'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']],
    )]
    public function readiness(Context $context): JsonResponse
    {
        return new JsonResponse(['data' => $this->readinessProvider->readiness($context)]);
    }

    #[Route(
        path: '/api/_admin/ucp/onboarding/bulk-enable',
        name: 'api.action.swag_agentic_commerce.ucp.onboarding.bulk_enable',
        methods: ['POST'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.editor']],
    )]
    public function bulkEnable(Request $request, Context $context): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            throw UcpConfigException::invalidJsonPayload();
        }

        $outcomes = $this->activator->activate(
            $this->salesChannelIds($payload),
            $this->capabilities($payload),
            $this->transports($payload),
            $context,
        );

        return new JsonResponse([
            'data' => [
                'results' => $outcomes,
                'enabled' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isEnabled()),
                'skipped' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isSkipped()),
                'failed' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isFailed()),
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function salesChannelIds(array $payload): array
    {
        $ids = $payload['salesChannelIds'] ?? null;
        if (!\is_array($ids) || !array_is_list($ids)) {
            throw UcpConfigException::invalidValue('$.salesChannelIds', 'must be a list');
        }

        $normalized = [];
        foreach ($ids as $index => $id) {
            if (!\is_string($id) || '' === trim($id)) {
                throw UcpConfigException::invalidValue(\sprintf('$.salesChannelIds[%d]', $index), 'must be a non-empty string');
            }

            $normalized[] = trim($id);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function capabilities(array $payload): array
    {
        if (!\array_key_exists('enabledCapabilities', $payload)) {
            return UcpCapabilityCatalog::defaultConfigKeys();
        }

        $capabilities = $this->stringList($payload['enabledCapabilities'], '$.enabledCapabilities');
        foreach ($capabilities as $capability) {
            if (!\in_array($capability, UcpCapabilityCatalog::allConfigKeys(), true)) {
                throw UcpConfigException::invalidValue('$.enabledCapabilities', \sprintf('unsupported capability "%s"', $capability));
            }
        }

        return $capabilities;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function transports(array $payload): array
    {
        if (!\array_key_exists('enabledTransports', $payload)) {
            return ['rest'];
        }

        $transports = $this->stringList($payload['enabledTransports'], '$.enabledTransports');
        foreach ($transports as $transport) {
            if (null === Transport::tryFrom($transport)) {
                throw UcpConfigException::invalidValue('$.enabledTransports', \sprintf('unsupported transport "%s"', $transport));
            }
        }

        return $transports;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw UcpConfigException::invalidValue($path, 'must be a list');
        }

        $normalized = [];
        foreach ($value as $index => $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw UcpConfigException::invalidValue(\sprintf('%s[%d]', $path, $index), 'must be a non-empty string');
            }

            $normalized[] = trim($entry);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<ChannelActivationOutcome>           $outcomes
     * @param \Closure(ChannelActivationOutcome): bool $matches
     */
    private function count(array $outcomes, \Closure $matches): int
    {
        return \count(array_filter($outcomes, $matches));
    }
}
