<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[Package('framework')]
final readonly class UcpConfigService
{
    private const DOMAIN = 'SwagAgenticCommerce.config.';

    /**
     * @var list<string>
     */
    private const KEYS = [
        'active',
        'ucpVersion',
        'profileUriStrategy',
        'customProfileUri',
        'enabledCapabilities',
        'enabledTransports',
        'continueUrlTemplate',
        'platformAllowlist',
        'remoteProfileAllowlist',
        'agentAllowlist',
        'embeddedAllowedOrigins',
        'embeddedFrameAncestors',
        'discoveryBudget',
        'webhookUrlOverride',
        'signaturePolicy',
        'idempotencyRequired',
    ];

    public function __construct(
        private UcpConfigRepositoryInterface $repository,
        private SystemConfigService $systemConfigService,
    ) {
    }

    public function getConfig(?string $salesChannelId = null): UcpConfig
    {
        if (null === $salesChannelId) {
            return UcpConfig::fromArray($this->legacyPayload(null));
        }

        $config = $this->repository->find($salesChannelId);
        if ($config !== null) {
            return $config;
        }

        $legacyPayload = $this->legacyPayload($salesChannelId);
        $config = UcpConfig::fromArray($legacyPayload);

        if ($this->hasLegacyValues($legacyPayload)) {
            $this->repository->save($salesChannelId, $config);
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
        if ($salesChannelIds === []) {
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
     * @param array<string, mixed> $payload
     */
    public function saveConfig(array $payload, ?string $salesChannelId = null): UcpConfig
    {
        $config = UcpConfig::fromArray($payload);

        if (null === $salesChannelId) {
            foreach ($config->toArray() as $key => $value) {
                $this->systemConfigService->set(self::DOMAIN.$key, $value, null);
            }

            return $config;
        }

        $this->repository->save($salesChannelId, $config);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyPayload(?string $salesChannelId): array
    {
        $payload = [];
        foreach (self::KEYS as $key) {
            $payload[$key] = $this->systemConfigService->get(self::DOMAIN.$key, $salesChannelId);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasLegacyValues(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }
}
