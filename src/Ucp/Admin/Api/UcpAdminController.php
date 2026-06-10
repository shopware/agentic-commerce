<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\AgenticDiscovery\DiscoveryBridgeInterface;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Profile\ProfilePreviewBuilder;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('checkout')]
final class UcpAdminController
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly UcpConfigService $configService,
        private readonly UcpSigningKeyService $signingKeyService,
        private readonly ProfilePreviewBuilder $profilePreviewBuilder,
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly DiscoveryBridgeInterface $discoveryBridge,
        private readonly PlatformProfileCacheRepositoryInterface $platformProfileCacheRepository,
    ) {
    }

    #[Route(path: '/api/_admin/ucp/sales-channels', name: 'api.action.swag_agentic_commerce.ucp.sales_channels', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function salesChannels(Context $context): JsonResponse
    {
        $salesChannels = $this->salesChannelViewProvider->all($context);
        $configs = $this->configService->getConfigs(array_column($salesChannels, 'id'));

        $salesChannels = array_map(
            fn (array $salesChannel): array => [
                ...$salesChannel,
                'ucp' => $this->salesChannelSummary($configs[$salesChannel['id']] ?? UcpConfig::fromArray([])),
            ],
            $salesChannels,
        );

        return new JsonResponse([
            'data' => $salesChannels,
            'meta' => [
                'shopwareVersion' => $this->shopwareVersionLabel(),
                'supportsAgenticDiscovery' => $this->discoveryBridge->isAvailable(),
                'supportsStoreApiMcp' => $this->versionDetector->supportsStoreApiMcp(),
            ],
        ]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}', name: 'api.action.swag_agentic_commerce.ucp.sales_channel', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function salesChannel(string $salesChannelId, Context $context): JsonResponse
    {
        $salesChannel = $this->salesChannelViewProvider->get($salesChannelId, $context);
        if (null === $salesChannel) {
            return new JsonResponse(['errors' => [['detail' => 'Sales channel not found.']]], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => [
                ...$salesChannel,
                'ucp' => $this->salesChannelSummary($this->configService->getConfig($salesChannelId)),
            ],
            'meta' => [
                'shopwareVersion' => $this->shopwareVersionLabel(),
                'supportsAgenticDiscovery' => $this->discoveryBridge->isAvailable(),
                'supportsStoreApiMcp' => $this->versionDetector->supportsStoreApiMcp(),
            ],
        ]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/config', name: 'api.action.swag_agentic_commerce.ucp.config.get', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function getConfig(string $salesChannelId): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->configService->getConfig($salesChannelId)->toArray(),
        ]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/config', name: 'api.action.swag_agentic_commerce.ucp.config.update', methods: ['PUT'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.editor']])]
    public function updateConfig(string $salesChannelId, Request $request): JsonResponse
    {
        $payload = $request->toArray();

        return new JsonResponse([
            'data' => $this->configService->saveConfig($payload, $salesChannelId)->toArray(),
        ]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/keys', name: 'api.action.swag_agentic_commerce.ucp.keys.list', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function keys(string $salesChannelId): JsonResponse
    {
        return new JsonResponse(['data' => $this->signingKeyService->all($salesChannelId)]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/keys', name: 'api.action.swag_agentic_commerce.ucp.keys.create', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.key_rotator']])]
    public function createKey(string $salesChannelId, Request $request): JsonResponse
    {
        $payload = $request->toArray();

        return new JsonResponse([
            'data' => $this->signingKeyService->create(
                $salesChannelId,
                isset($payload['kid']) && \is_string($payload['kid']) ? $payload['kid'] : null,
                isset($payload['algorithm']) && \is_string($payload['algorithm']) ? $payload['algorithm'] : 'ES256',
            ),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/keys/{kid}/retire', name: 'api.action.swag_agentic_commerce.ucp.keys.retire', methods: ['POST'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.key_rotator']])]
    public function retireKey(string $salesChannelId, string $kid): JsonResponse
    {
        if (!$this->signingKeyService->retire($salesChannelId, $kid)) {
            return new JsonResponse(['errors' => [['detail' => 'Signing key not found.']]], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/keys/{kid}', name: 'api.action.swag_agentic_commerce.ucp.keys.delete', methods: ['DELETE'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.key_rotator']])]
    public function deleteKey(string $salesChannelId, string $kid): JsonResponse
    {
        if (!$this->signingKeyService->delete($salesChannelId, $kid)) {
            return new JsonResponse(['errors' => [['detail' => 'Signing key not found.']]], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/api/_admin/ucp/sales-channels/{salesChannelId}/profile-preview', name: 'api.action.swag_agentic_commerce.ucp.profile_preview', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function profilePreview(string $salesChannelId, Context $context): JsonResponse
    {
        $config = $this->configService->getConfig($salesChannelId);
        $baseUri = $this->salesChannelViewProvider->firstDomainUrl($salesChannelId, $context) ?? 'https://example.invalid';

        return new JsonResponse([
            'data' => $this->profilePreviewBuilder->build($config, $baseUri, $salesChannelId),
        ]);
    }

    #[Route(path: '/api/_admin/ucp/platform-profiles', name: 'api.action.swag_agentic_commerce.ucp.platform_profiles', methods: ['GET'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']])]
    public function platformProfiles(): JsonResponse
    {
        $profiles = $this->platformProfileCacheRepository->all(true);

        return new JsonResponse([
            'data' => array_map(
                fn (string $uri, PlatformProfile $profile): array => [
                    'id' => $this->profileCacheId($uri),
                    'uri' => $uri,
                    'profile' => $profile->toArray(),
                ],
                array_keys($profiles),
                $profiles,
            ),
        ]);
    }

    #[Route(path: '/api/_admin/ucp/platform-profiles/{id}', name: 'api.action.swag_agentic_commerce.ucp.platform_profiles.delete', methods: ['DELETE'], defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.editor']])]
    public function deletePlatformProfile(string $id): JsonResponse
    {
        if (!$this->platformProfileCacheRepository->delete($this->profileCacheUri($id))) {
            return new JsonResponse(['errors' => [['detail' => 'Platform profile cache entry not found.']]], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesChannelSummary(UcpConfig $config): array
    {
        return [
            'active' => $config->active,
            'signaturePolicy' => $config->signaturePolicy,
            'idempotencyRequired' => $config->idempotencyRequired,
            'enabledCapabilities' => $config->enabledCapabilities,
            'enabledTransports' => $config->enabledTransports,
        ];
    }

    private function shopwareVersionLabel(): string
    {
        $version = $this->versionDetector->currentVersion();

        if (str_contains($version, '9999999') && 1 === preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            return $matches[1].'-dev';
        }

        if (\strlen($version) > 18) {
            return substr($version, 0, 18).'…';
        }

        return $version;
    }

    private function profileCacheId(string $uri): string
    {
        return rtrim(strtr(base64_encode($uri), '+/', '-_'), '=');
    }

    private function profileCacheUri(string $id): string
    {
        $encoded = strtr($id, '-_', '+/');
        $encoded .= str_repeat('=', (4 - \strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        return \is_string($decoded) && '' !== $decoded ? $decoded : rawurldecode($id);
    }
}
