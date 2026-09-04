<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;

/** @internal */
#[Package('framework')]
final class UcpConfigService
{
    private const DOMAIN = 'SwagAgenticCommerce.config.';

    /**
     * @var list<string>
     */
    private const KEYS = [
        'active',
        'profileDomain',
        'enabledCapabilities',
        'enabledTransports',
        'continueUrlTemplate',
        'platformAllowlist',
        'remoteProfileAllowlist',
        'agentAllowlist',
        'embeddedAllowedOrigins',
        'embeddedFrameAncestors',
        'discoveryBudget',
        'catalogResultLimit',
        'webhookUrlOverride',
        'signaturePolicy',
        'idempotencyRequired',
    ];

    public function __construct(
        private readonly UcpConfigRepositoryInterface $repository,
        private readonly LegacyConfigStoreInterface $legacyConfigStore,
        private readonly ?AgenticFilesCoreBridgeInterface $agenticFilesCoreBridge = null,
        private readonly ?UcpSigningKeyService $signingKeyService = null,
        private readonly bool $allowHttpLocalWebhookOverride = false,
    ) {
    }

    public function getConfig(?string $salesChannelId = null): UcpConfig
    {
        if (null === $salesChannelId) {
            return UcpConfig::fromArray($this->legacyPayload(null), $this->allowHttpLocalWebhookOverride);
        }

        $config = $this->repository->find($salesChannelId);
        if (null !== $config) {
            return $config;
        }

        $legacyPayload = $this->legacyPayload($salesChannelId);
        $config = UcpConfig::fromArray($legacyPayload, $this->allowHttpLocalWebhookOverride);

        if ($this->hasLegacyValues($legacyPayload)) {
            // Compatibility bridge for legacy SystemConfig-backed setups,
            // including warm local installations that existed before the
            // sales-channel scoped config table was populated. Keep this until a
            // dedicated backfill migration/command replaces read-time migration.
            $this->repository->save($salesChannelId, $config);

            if ($config->active) {
                $this->agenticFilesCoreBridge?->enableForSalesChannel($salesChannelId);
            }
        }

        return $config;
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array<string, UcpConfig>
     */
    public function getConfigs(array $salesChannelIds): array
    {
        if ([] === $salesChannelIds) {
            return [];
        }

        $configs = $this->repository->findMany($salesChannelIds);

        foreach ($salesChannelIds as $salesChannelId) {
            if (isset($configs[$salesChannelId])) {
                continue;
            }

            $configs[$salesChannelId] = $this->getConfig($salesChannelId);
        }

        return $configs;
    }

    /**
     * Persist a (possibly partial) config payload. The incoming keys are merged
     * over the currently stored config, so callers that only own a subset of the
     * fields do not reset the rest: the admin UI saves the Exposure subset
     * (active / profileDomain / capabilities / transports) while signature
     * policy, signing keys and the advanced host/delivery settings are managed
     * via console commands (ucp:config:* / ucp:signing-keys:*). Whichever writes last
     * preserves the other's fields.
     *
     * @param array<string, mixed> $payload
     */
    public function saveConfig(array $payload, ?string $salesChannelId = null): UcpConfig
    {
        if (null === $salesChannelId) {
            $config = UcpConfig::fromArray($payload, $this->allowHttpLocalWebhookOverride);
            foreach ($config->toArray() as $key => $value) {
                $this->legacyConfigStore->set(self::DOMAIN.$key, $value, null);
            }

            return $config;
        }

        $merged = array_merge($this->getConfig($salesChannelId)->toArray(), $payload);
        $config = UcpConfig::fromArray($merged, $this->allowHttpLocalWebhookOverride);

        $this->repository->save($salesChannelId, $config);

        if ($config->active) {
            $this->agenticFilesCoreBridge?->enableForSalesChannel($salesChannelId);
            $this->ensureSigningKey($salesChannelId);
        }

        return $config;
    }

    /**
     * Auto-provision a signing key so that an activated sales channel is usable
     * under the default "strict" signature policy without a manual key-creation
     * step (redesign §10.1). Idempotent: a no-op when a non-retired key already
     * exists, and self-healing if the only key was deleted.
     */
    private function ensureSigningKey(string $salesChannelId): void
    {
        if (null === $this->signingKeyService) {
            return;
        }

        foreach ($this->signingKeyService->all($salesChannelId) as $key) {
            if ('retired' !== ($key['status'] ?? null)) {
                return;
            }
        }

        $this->signingKeyService->create($salesChannelId, null, 'ES256');
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyPayload(?string $salesChannelId): array
    {
        $payload = [];
        foreach (self::KEYS as $key) {
            $payload[$key] = $this->legacyConfigStore->get(self::DOMAIN.$key, $salesChannelId);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasLegacyValues(array $payload): bool
    {
        foreach ($payload as $value) {
            if (null !== $value) {
                return true;
            }
        }

        return false;
    }
}
