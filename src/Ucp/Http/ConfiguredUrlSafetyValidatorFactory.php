<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

/**
 * Builds the SDK's {@see UrlSafetyValidator} from the shop's own UCP
 * configuration instead of the SDK bundle's static `allowed_profile_hosts`
 * semantic config (which the plugin never populates, leaving the validator
 * with an empty allowlist that rejects every remote profile fetch and
 * webhook delivery).
 *
 * The validator is a single, container-wide service while the plugin's
 * allowlists are per sales channel, so the factory unions every channel's
 * (and the global scope's) remote-profile and platform allowlists plus all
 * sales-channel domain hosts. That superset is safe: per-channel policy is
 * already enforced upstream by the request-context factory's
 * assertSafeProfileUri() before any fetch happens — this validator is the
 * SSRF defense-in-depth layer behind it.
 *
 * @internal
 */
#[Package('framework')]
final class ConfiguredUrlSafetyValidatorFactory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UcpConfigService $configService,
        private readonly bool $profileFetchingDevelopmentMode = false,
    ) {
    }

    public function create(): UrlSafetyValidator
    {
        return new UrlSafetyValidator($this->allowedHosts(), null, $this->profileFetchingDevelopmentMode);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = [];
        foreach ($this->configs() as $config) {
            $hosts = [...$hosts, ...$config->remoteProfileAllowlist, ...$config->platformAllowlist];
        }

        $hosts = [...$hosts, ...$this->salesChannelDomainHosts()];
        $hosts = array_map(static fn (string $host): string => strtolower(trim($host)), $hosts);
        $hosts = array_values(array_unique(array_filter($hosts, static fn (string $host): bool => '' !== $host)));
        sort($hosts);

        return $hosts;
    }

    /**
     * @return list<UcpConfig>
     */
    private function configs(): array
    {
        try {
            $ids = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM sales_channel');
            $ids = array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));

            $configs = array_values($this->configService->getConfigs($ids));
            $configs[] = $this->configService->getConfig(null);

            return $configs;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function salesChannelDomainHosts(): array
    {
        try {
            $urls = $this->connection->fetchFirstColumn('SELECT url FROM sales_channel_domain');
        } catch (\Throwable) {
            return [];
        }

        $hosts = [];
        foreach ($urls as $url) {
            $host = parse_url((string) $url, \PHP_URL_HOST);
            if (\is_string($host) && '' !== $host) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }
}
